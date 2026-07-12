<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('export_templates', function (Blueprint $table) {
            // Which export kind a template offers itself for: all | pdf | csv.
            $table->string('applies_to', 10)->default('all');
        });
    }

    public function down(): void
    {
        Schema::table('export_templates', function (Blueprint $table) {
            $table->dropColumn('applies_to');
        });
    }
};
