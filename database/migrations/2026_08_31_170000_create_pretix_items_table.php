<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ticket types of an event, by name.
 *
 * WHY THEY HAVE TO BE STORED. Order positions carry only a numeric `item` id -
 * measured on the real data, event ac-friends-2026 uses 3, 4 and 23. Nobody picks a
 * ticket type by typing 23, so the names have to be available to build a selection
 * from. They exist in pretix behind a separate endpoint, and fetching them on every
 * page load would make choosing a ticket type depend on pretix being reachable at
 * that moment - for a screen whose whole job is to export data we already hold.
 *
 * Refreshed on every order import: one extra request per event, five events here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pretix_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pretix_connection_id')->nullable();
            $table->string('event_slug');

            // pretix' own id, the one the order positions refer to.
            $table->unsignedBigInteger('item_id');
            $table->string('name');

            $table->timestamps();

            // One row per ticket type per event. A second import must update, never
            // duplicate - otherwise the picker grows a copy of every type per run.
            $table->unique(['pretix_connection_id', 'event_slug', 'item_id'], 'pretix_items_unique');
            $table->index(['event_slug', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pretix_items');
    }
};
