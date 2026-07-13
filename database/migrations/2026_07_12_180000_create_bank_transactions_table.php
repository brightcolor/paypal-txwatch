<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank account movements imported from a Sparkasse statement (CAMT.053 XML or
 * MT940). The bridge to reality: a PayPal payout (T04xx/T20xx leaving the
 * PayPal balance) should show up here as a credit, and pretix bank transfers
 * arrive here directly. Reconciling closes the loop TxWatch -> bank account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->date('booked_on')->nullable()->index();
            $table->date('valued_on')->nullable()->index();
            // Signed: credit (incoming) positive, debit (outgoing) negative.
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('EUR');
            $table->text('purpose')->nullable();
            $table->string('counterparty_name')->nullable();
            $table->string('counterparty_iban', 40)->nullable();
            $table->string('end_to_end_id')->nullable();
            $table->string('bank_ref')->nullable();
            $table->string('source_format', 10)->nullable(); // camt | mt940
            // Dedupe across re-imports of overlapping statements.
            $table->string('import_hash', 64)->unique();
            $table->string('reconciliation_status', 12)->default('unmatched')->index(); // unmatched|matched|ignored
            $table->foreignId('matched_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->string('match_method', 12)->nullable(); // payout|pretix|manual
            $table->json('raw')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
    }
};
