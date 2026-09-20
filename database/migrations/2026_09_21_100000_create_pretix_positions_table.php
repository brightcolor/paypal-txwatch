<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per ACTIVE ticket position, derived from the stored pretix orders.
 *
 * WHY A TABLE AND NOT A QUERY OVER raw_payload: the audience questions are
 * group-by questions - who bought at which events, how often, which ticket type. In
 * the payload every one of those means unpacking every stored JSON document per
 * screen, and a sortable, paginated buyer list on top of that is hand-built. As a
 * table it is plain SQL and an ordinary Filament table.
 *
 * IT IS DERIVED, never edited: `pretix:rebuild-positions` builds it from the orders
 * at any time, so there is no second truth to keep in sync - only a copy that can be
 * thrown away.
 *
 * CANCELLED POSITIONS STAY OUT. This table is the audience; a cancelled ticket is a
 * person who will not be there. Cancellation rates are counted on the order level,
 * where the status lives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pretix_positions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('pretix_connection_id')->nullable();
            $table->unsignedBigInteger('pretix_order_id');

            $table->string('event_slug', 255);
            $table->string('order_code', 255);
            $table->string('order_status', 255)->nullable();
            $table->string('payment_provider', 255)->nullable();

            // The buyer's identity. NULL when the order carries no address: those
            // tickets still count, but they must not bundle into one phantom person.
            $table->string('buyer_email', 255)->nullable();
            $table->string('buyer_name', 255)->nullable();

            // pretix' own ids: the position and the ticket type it refers to.
            $table->unsignedBigInteger('position_id');
            $table->unsignedBigInteger('item_id');

            $table->decimal('price', 10, 2)->default(0);
            $table->string('voucher', 255)->nullable();
            $table->string('attendee_name', 255)->nullable();

            $table->string('zipcode', 64)->nullable();
            $table->string('city', 255)->nullable();
            $table->string('country', 64)->nullable();

            $table->timestamp('ordered_at')->nullable();
            $table->timestamps();

            // A second import must update, never duplicate.
            $table->unique(
                ['pretix_connection_id', 'event_slug', 'order_code', 'position_id'],
                'pretix_positions_unique',
            );

            $table->index(['event_slug', 'buyer_email']);
            $table->index('buyer_email');
            $table->index('ordered_at');
            $table->index('item_id');
            $table->index('pretix_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pretix_positions');
    }
};
