<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The journal for the Enable Banking pull: what the bank sent, recorded and
 * nothing else.
 *
 * WHY A TABLE OF ITS OWN instead of writing straight into bank_transactions with
 * a flag. The flag looks cheaper and is the more dangerous of the two: every
 * report, every EÜR query and the reconciliation would have to remember to
 * exclude these rows, and the one query that forgets puts unverified bank data
 * into the books silently. A separate table cannot leak - nothing reads it yet.
 *
 * THE PROMOTION PATH IS PLANNED, NOT IMPROVISED: `promoted_at` and
 * `bank_transaction_id` stay empty for now. When these entries are to count, a
 * command copies them through the same importer the file import uses, sets both
 * columns, and the journal keeps the audit trail of what came in when.
 *
 * `import_hash` is computed exactly as BankStatementImporter does it, so a
 * journal row and the later bank_transactions row share one identity - and the
 * promotion cannot create a duplicate of something the file import already had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enable_banking_journal', function (Blueprint $table) {
            $table->id();

            // Same identity the importer uses - dedupes across pulls AND against
            // an already imported statement.
            $table->string('import_hash', 64)->unique();

            $table->date('booked_on')->nullable();
            $table->date('valued_on')->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('EUR');
            $table->text('purpose')->nullable();
            $table->string('counterparty_name')->nullable();
            $table->string('counterparty_iban')->nullable();
            $table->string('end_to_end_id')->nullable();
            $table->string('bank_ref')->nullable();

            /*
             * The order code found in the purpose, if any. Extracted at pull time
             * rather than later: the text is what the bank sent, and pinning the
             * match to it now makes the later pretix step reviewable BEFORE it
             * books anything.
             */
            $table->string('pretix_order_code', 32)->nullable()->index();

            // Untouched payload, so a later promotion never depends on today's
            // mapping being complete.
            $table->json('raw')->nullable();

            $table->timestamp('pulled_at');

            // Both null while the journal is only a journal.
            $table->timestamp('promoted_at')->nullable();
            $table->unsignedBigInteger('bank_transaction_id')->nullable();

            $table->timestamps();

            $table->index('booked_on');
            $table->index('promoted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enable_banking_journal');
    }
};
