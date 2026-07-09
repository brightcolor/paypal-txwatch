<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Scopes "customer" role users to a single customer's events/reports.
            $table->foreignId('customer_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn('is_active');
        });
    }
};
