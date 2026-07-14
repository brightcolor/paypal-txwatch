<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-row FinTS/HBCI bank connection (replaces the GoCardless connection).
 * Talks directly to the bank (e.g. Sparkasse) via phpFinTS - no third-party
 * aggregator. Credentials and the persisted FinTS session are stored encrypted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fints_connections', function (Blueprint $table) {
            $table->id();

            // Static configuration (reproducible from here on every request).
            $table->string('bank_code', 20)->nullable();      // BLZ
            $table->string('fints_url')->nullable();           // PIN/TAN HBCI URL
            $table->string('product_id')->nullable();          // DK-Registrierungsnummer
            $table->string('product_version', 20)->default('1.0');

            // Secrets (encrypted at rest).
            $table->text('username')->nullable();
            $table->text('pin')->nullable();

            // TAN method selection + chosen account.
            $table->string('tan_mode')->nullable();
            $table->string('tan_medium')->nullable();
            $table->string('iban')->nullable();

            // Persisted FinTS session (durable, logged-in) + transient TAN state.
            $table->longText('persisted_state')->nullable();   // encrypted
            $table->longText('pending_state')->nullable();      // encrypted
            $table->longText('pending_action')->nullable();     // encrypted
            $table->text('tan_challenge')->nullable();
            $table->longText('tan_image')->nullable();          // base64 data URI

            $table->string('status', 16)->default('new'); // new|needs_tan|active|needs_reauth|error
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fints_connections');
    }
};
