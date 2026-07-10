<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pretix_connections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // e.g. https://pretix.eu or a self-hosted instance like https://hsp-tickets.de
            $table->string('base_url');
            $table->string('organizer_slug');
            $table->text('api_token'); // encrypted cast

            $table->boolean('is_active')->default(true);
            $table->boolean('sync_enabled')->default(true);

            // Per-transaction fee (in cents) for bank-transfer orders, which pretix
            // itself does not carry. Applied on import so the billing reflects it.
            $table->unsignedInteger('bank_transfer_fee_cents')->default(20);

            // Only import orders NOT paid via PayPal by default, to avoid double-counting
            // with the PayPal transaction sync.
            $table->boolean('import_paypal_orders')->default(false);

            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_successful_sync_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pretix_connections');
    }
};
