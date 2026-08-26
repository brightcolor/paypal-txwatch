<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHY an order was marked paid - one append-only row per attempt.
 *
 * Until now the only trace was `bank_transactions.pretix_reported_at` plus an error
 * column that the next attempt overwrote. That answers WHEN, never WHY: not which
 * money it was, not what in the purpose identified the order, not which switch
 * allowed it. For a write that sends a customer their tickets, "it says reported"
 * is not an answer anyone can check.
 *
 * FAILED AND REFUSED ATTEMPTS ARE RECORDED TOO. The sibling question - why an order
 * was NOT marked - used to be answerable only until the next run overwrote the
 * error, and "the automation did nothing" is the harder complaint to investigate.
 *
 * APPEND-ONLY. Nothing in the application updates or deletes these rows; the UI
 * offers neither. A record that can be corrected afterwards is not a record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pretix_payment_confirmations', function (Blueprint $table) {
            $table->id();

            // WHICH ORDER. Kept as plain values, not only as a foreign key: an order
            // deleted from pretix must not take the proof of its confirmation with it.
            $table->unsignedBigInteger('pretix_connection_id')->nullable();
            $table->string('event_slug')->nullable();
            $table->string('order_code')->nullable();
            $table->index(['pretix_connection_id', 'event_slug', 'order_code'], 'ppc_order_idx');

            // WHICH SWITCH allowed it - the event whose setting was read.
            $table->unsignedBigInteger('event_id')->nullable()->index();

            /*
             * WHICH MONEY. Both sources can trigger a confirmation: a row in the
             * books (imported statement or promoted journal entry) and a journal
             * entry straight from the bank pull. Whichever it was has to be
             * followable back to the transaction someone can look at.
             */
            $table->unsignedBigInteger('bank_transaction_id')->nullable()->index();
            $table->unsignedBigInteger('journal_entry_id')->nullable()->index();
            $table->string('source', 20);

            // THE EVIDENCE, copied rather than referenced: the purpose is what
            // identified the order, and it must still read the same in a year.
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->text('purpose')->nullable();
            $table->string('counterparty_name')->nullable();
            $table->date('booked_on')->nullable();

            // WHAT WAS DECIDED and why, in words that answer the question directly.
            $table->string('outcome', 20)->index();
            $table->string('reason', 40)->nullable();
            $table->text('message')->nullable();

            // WHO. Empty for the automation - and that emptiness is the statement.
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->boolean('automatic')->default(true);

            $table->unsignedInteger('pretix_payment_local_id')->nullable();
            $table->timestamp('at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pretix_payment_confirmations');
    }
};
