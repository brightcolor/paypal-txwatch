<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Unlock PIN for two-factor accounts (hashed like a password), the count of
 * wrong PINs in a row, and a counter that invalidates every confirmed-device
 * cookie of the user when it moves on (PIN locked, 2FA switched off, "forget
 * the other devices").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('two_factor_pin')->nullable()->after('two_factor_confirmed_at');
            $table->timestamp('two_factor_pin_set_at')->nullable()->after('two_factor_pin');
            $table->unsignedSmallInteger('two_factor_pin_failures')->default(0)->after('two_factor_pin_set_at');
            $table->unsignedInteger('two_factor_device_epoch')->default(0)->after('two_factor_pin_failures');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_pin', 'two_factor_pin_set_at', 'two_factor_pin_failures', 'two_factor_device_epoch']);
        });
    }
};
