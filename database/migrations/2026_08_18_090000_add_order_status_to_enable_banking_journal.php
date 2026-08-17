<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records WHICH STATE the recognised order was in.
 *
 * WHY THIS COLUMN EXISTS, measured on the real data: 986 of 1025 orders are long
 * since paid, only 5 are still open. The matcher looked at open ones only, so
 * everything belonging to an already settled order came out as "no assignment" -
 * indistinguishable from a real gap, and that is the normal case rather than the
 * exception.
 *
 * With the status stored, the journal can say three different things instead of
 * two: belongs to an OPEN order (worth booking), belongs to a PAID one (nothing to
 * do - and if the amount fits, a possible double payment), or belongs to nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enable_banking_journal', function (Blueprint $table) {
            // pretix order status at the time of recognition: n = pending, p = paid,
            // c = cancelled, e = expired. Kept raw rather than mapped, so a new
            // pretix status does not silently become something else.
            $table->string('pretix_order_status', 2)->nullable()->index();

            /*
             * Someone paid an order that was already settled. Flagged rather than
             * derived on the fly: it is the one finding here that costs money if
             * missed, and it has to be filterable without recomputing.
             */
            $table->boolean('possible_double_payment')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('enable_banking_journal', function (Blueprint $table) {
            $table->dropColumn(['pretix_order_status', 'possible_double_payment']);
        });
    }
};
