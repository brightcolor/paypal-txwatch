<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How long the pull has been failing, and what it said the FIRST time.
 *
 * WHAT HAPPENED WITHOUT THIS (21.08. - 26.08.2026, six days, no transactions): a
 * network timeout on the host made one pull fail. Every following run then refused
 * with "Keine aktive Bankverbindung" and wrote THAT over `last_error` - so the
 * original cause was gone after six hours, and what remained described the lockout
 * rather than the reason for it.
 *
 * `first_error` holds the message that started the streak until a pull succeeds.
 * `failed_since` and `failure_count` make "since when" and "how often" answerable
 * without reading logs - and let the command warn after the second failure instead
 * of writing a console line nobody reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enable_banking_connections', function (Blueprint $table) {
            $table->timestamp('failed_since')->nullable();
            $table->unsignedInteger('failure_count')->default(0);
            $table->text('first_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('enable_banking_connections', function (Blueprint $table) {
            $table->dropColumn(['failed_since', 'failure_count', 'first_error']);
        });
    }
};
