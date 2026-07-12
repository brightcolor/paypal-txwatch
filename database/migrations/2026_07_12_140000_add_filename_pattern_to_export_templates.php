<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('export_templates', function (Blueprint $table) {
            // Optional placeholder pattern for the download filename, e.g.
            // "Abrechnung {{ event.name }} {{ period.to }}".
            $table->string('filename_pattern')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('export_templates', function (Blueprint $table) {
            $table->dropColumn('filename_pattern');
        });
    }
};
