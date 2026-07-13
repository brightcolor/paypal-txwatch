<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A GoCardless Bank Account Data (PSD2) connection to the operator's bank
 * account. Read-only. Secrets are encrypted at rest. One row is enough for the
 * single Sparkasse account; the model treats it as a singleton.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_connections', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20)->default('gocardless');
            // API credentials from the GoCardless portal (encrypted).
            $table->text('secret_id')->nullable();
            $table->text('secret_key')->nullable();
            // Chosen bank + the live consent/requisition state.
            $table->string('institution_id')->nullable();
            $table->string('institution_name')->nullable();
            $table->string('requisition_id')->nullable();
            $table->string('requisition_ref')->nullable();
            $table->string('agreement_id')->nullable();
            $table->json('account_ids')->nullable();
            // new | linking | connected | expired | error
            $table->string('status', 12)->default('new');
            $table->timestamp('consent_expires_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_connections');
    }
};
