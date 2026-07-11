<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pretix_orders', function (Blueprint $table) {
            // Actual VAT contained in the order per pretix (sum of the
            // positions' and fees' tax_value) - more accurate than assuming a
            // flat rate, especially with mixed 19%/7% positions.
            $table->decimal('tax_total', 14, 2)->nullable()->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('pretix_orders', function (Blueprint $table) {
            $table->dropColumn('tax_total');
        });
    }
};
