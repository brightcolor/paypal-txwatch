<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widens bank_transactions.source_format from 10 to 20 characters.
 *
 * WHY: the column was sized for `camt`, `mt940` and `fints`. The Enable Banking
 * path writes `enablebanking` - 13 characters - and PostgreSQL refused the insert
 * with "value too long for type character varying(10)". The pull fetched the
 * transactions correctly and then dropped them on the floor at the last step.
 *
 * WIDENED RATHER THAN SHORTENED. `eb` would have fit without a migration, but
 * this column is read by humans - in exports and when someone asks where a
 * booking came from - and nothing in the code branches on its value. A cryptic
 * two-letter code to avoid a one-line migration is a bad trade.
 *
 * WHY THE TESTS DID NOT CATCH IT: they run on SQLite, which ignores varchar
 * lengths entirely. The insert passed there and failed only on PostgreSQL. The
 * guard for this is therefore a schema assertion, not a round-trip - see
 * SourceFormatFitsColumnTest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->string('source_format', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        /*
         * Deliberately NOT narrowing back to 10: any row written by the Enable
         * Banking path would be truncated or refused, and a rollback that
         * destroys data is worse than one that leaves a column too wide.
         */
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->string('source_format', 20)->nullable()->change();
        });
    }
};
