<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pretix_connections', function (Blueprint $table) {
            // Human-readable result of the last background import (counts), plus a
            // flag so the UI can show "läuft…" while the queued job is in flight.
            $table->string('last_import_summary')->nullable()->after('last_error');
            $table->boolean('import_running')->default(false)->after('last_import_summary');
        });
    }

    public function down(): void
    {
        Schema::table('pretix_connections', function (Blueprint $table) {
            $table->dropColumn(['last_import_summary', 'import_running']);
        });
    }
};
