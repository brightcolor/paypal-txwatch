<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // pretix-sourced transactions (bank transfer & other non-PayPal payment
            // methods) have no PayPal account.
            $table->foreignId('paypal_account_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('paypal_account_id')->nullable(false)->change();
        });
    }
};
