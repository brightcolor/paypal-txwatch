<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Single-row Enable Banking (PSD2) connection - the second, registration-free
 * way to a bank account next to FinTS.
 *
 * The customer authorises on their own bank's website; what is stored here is a
 * session id that grants READ access to balances and transactions, nothing more.
 * No PIN, no TAN, no credentials.
 *
 * WHY THE CONSENT DEADLINE IS A COLUMN and not derived: PSD2 caps a consent at
 * 90 days, and when it lapses the daily pull simply stops returning data. Without
 * a stored date nobody can be warned BEFORE that happens - and a bank feed that
 * quietly went dry is only noticed when the books don't add up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enable_banking_connections', function (Blueprint $table) {
            $table->id();

            // Which bank, as Enable Banking names it (their `aspsp.name`, which
            // is also what the auth call expects back verbatim).
            $table->string('aspsp_name')->nullable();
            $table->string('aspsp_country', 2)->nullable();

            /*
             * The session id is a bearer credential for this account's data, so
             * it is encrypted at rest - same treatment as the FinTS PIN. An
             * attacker with the database alone gets nothing usable.
             */
            $table->text('session_id')->nullable();

            /*
             * The accounts the consent covers, as returned when the session was
             * opened: uid, iban, currency, product. Encrypted because the uid is
             * the handle used to read transactions.
             */
            $table->longText('accounts')->nullable();

            // The account actually pulled, for display and for the status page.
            $table->string('iban')->nullable();

            // When the bank's consent lapses. Warned about before it does.
            $table->timestamp('access_valid_until')->nullable();

            /*
             * The one-shot CSRF token for the redirect round trip. Kept here and
             * not only in the HTTP session so a callback arriving in a fresh
             * session (different browser, restarted container) can still be
             * rejected as unknown instead of accepted blindly.
             */
            $table->string('pending_state', 64)->nullable();
            $table->timestamp('pending_state_expires_at')->nullable();

            $table->string('status', 16)->default('new'); // new|active|expired|error
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enable_banking_connections');
    }
};
