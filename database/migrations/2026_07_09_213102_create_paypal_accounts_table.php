<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paypal_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('mode')->default('sandbox'); // sandbox|live
            $table->text('client_id'); // encrypted cast
            $table->text('client_secret'); // encrypted cast
            $table->string('default_currency', 3)->nullable();
            $table->boolean('is_active')->default(true);

            // Sync scheduling
            $table->boolean('sync_enabled')->default(true);
            $table->unsignedInteger('sync_interval_minutes')->default(15);
            $table->unsignedInteger('lookback_hours')->nullable();

            // Cached OAuth token (encrypted) - avoids a request on every job tick
            $table->text('access_token')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();

            // Status surfaced in the UI
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_successful_sync_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paypal_accounts');
    }
};
