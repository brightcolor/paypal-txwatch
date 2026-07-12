<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Correctness fix (audit 2026-07-12): PayPal revisions of the same
 * transaction_id are stored as separate rows by design (change history), but
 * every revenue aggregation summed ALL of them - double counting. This adds a
 * superseded_at marker: only the latest revision counts (scopeCurrentRevision),
 * history stays visible and undeletable.
 *
 * Also reclassifies T02xx (currency conversion legs) and T03xx (bank deposits
 * INTO the PayPal balance) as ledger-only: they are account movements, not
 * revenue (a 1.000-EUR deposit is not 1.000 EUR of sales).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->timestamp('superseded_at')->nullable()->index();
        });

        // Backfill 1: mark all but the newest revision per (account, txid).
        $duplicates = DB::table('transactions')
            ->select('paypal_account_id', 'transaction_id')
            ->whereNotNull('transaction_id')
            ->whereNotNull('paypal_account_id')
            ->groupBy('paypal_account_id', 'transaction_id')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($duplicates as $dup) {
            $rows = DB::table('transactions')
                ->where('paypal_account_id', $dup->paypal_account_id)
                ->where('transaction_id', $dup->transaction_id)
                ->orderByDesc('transaction_updated_date')
                ->orderByDesc('id')
                ->get(['id']);

            $keep = $rows->first()->id;

            DB::table('transactions')
                ->where('paypal_account_id', $dup->paypal_account_id)
                ->where('transaction_id', $dup->transaction_id)
                ->where('id', '<>', $keep)
                ->update(['superseded_at' => now()]);
        }

        // Backfill 2: T02xx/T03xx are ledger-only from now on.
        DB::table('transactions')
            ->where(function ($q) {
                $q->where('transaction_event_code', 'like', 'T02%')
                    ->orWhere('transaction_event_code', 'like', 'T03%');
            })
            ->update(['is_ledger' => true]);
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('superseded_at');
        });
    }
};
