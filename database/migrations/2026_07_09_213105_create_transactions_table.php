<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paypal_account_id')->constrained()->cascadeOnDelete();

            // Event assignment
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('assignment_method')->nullable(); // manual|rule|none
            $table->foreignId('assignment_rule_id')->nullable()
                ->constrained('event_assignment_rules')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();

            // PayPal identifiers - transaction_id is NOT globally unique in PayPal reports
            $table->string('transaction_id');
            $table->string('paypal_reference_id')->nullable();
            $table->string('paypal_reference_id_type')->nullable();
            $table->string('invoice_id')->nullable();
            $table->string('custom_field')->nullable();

            $table->string('transaction_event_code')->nullable();
            $table->string('transaction_status')->nullable();
            $table->timestamp('transaction_initiation_date')->nullable();
            $table->timestamp('transaction_updated_date')->nullable();

            $table->decimal('gross_amount', 14, 2)->nullable();
            $table->decimal('fee_amount', 14, 2)->nullable();
            $table->decimal('net_amount', 14, 2)->nullable();
            $table->string('currency', 3)->nullable();

            $table->string('payer_name')->nullable();
            $table->string('payer_email')->nullable();
            $table->string('payer_country_code', 2)->nullable();

            $table->string('payment_method_type')->nullable();
            $table->string('instrument_type')->nullable();
            $table->string('protection_eligibility')->nullable();

            $table->string('subject')->nullable();
            $table->text('note')->nullable();
            $table->json('item_info')->nullable();

            $table->json('raw_payload');
            $table->string('raw_hash', 64);

            // Robust idempotency key: account + txn id + event code + dates + ref id + amount + raw hash.
            // PayPal can list the same transaction_id more than once (fee adjustments, related
            // events, corrections), so we must not dedupe on transaction_id alone.
            $table->string('dedupe_key', 191)->unique();

            $table->timestamp('imported_at');
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            $table->index('transaction_id');
            $table->index('invoice_id');
            $table->index('custom_field');
            $table->index('transaction_status');
            $table->index('transaction_event_code');
            $table->index('currency');
            $table->index('transaction_initiation_date');
            $table->index('paypal_reference_id');
            $table->index(['paypal_account_id', 'transaction_initiation_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
