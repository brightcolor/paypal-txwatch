<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Set for events auto-created/matched from pretix; the slug is what
            // links a local Event to its pretix event (and to the event part of
            // the order numbers).
            $table->string('pretix_event_slug')->nullable()->unique()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('pretix_event_slug');
        });
    }
};
