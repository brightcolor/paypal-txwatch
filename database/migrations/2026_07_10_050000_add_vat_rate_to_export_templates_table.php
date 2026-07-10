<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('export_templates', function (Blueprint $table) {
            // Default German standard VAT rate; the export dialog can override it per export.
            $table->decimal('vat_rate', 5, 2)->default(19.00)->after('footer_note');
        });
    }

    public function down(): void
    {
        Schema::table('export_templates', function (Blueprint $table) {
            $table->dropColumn('vat_rate');
        });
    }
};
