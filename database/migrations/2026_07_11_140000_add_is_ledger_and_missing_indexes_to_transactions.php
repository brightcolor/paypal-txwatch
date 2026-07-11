<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Materializes Transaction::LEDGER_ONLY_PREFIXES as a boolean so the
            // hot revenue queries (dashboard, chart, reports, list) can use a
            // plain indexed equality instead of a stack of NOT LIKE predicates
            // that can never use an index. Kept in sync by a saving hook on the
            // model (single write path).
            $table->boolean('is_ledger')->default(false)->after('transaction_event_code');

            // Postgres does NOT auto-index FK columns; these are filtered/joined
            // constantly (customer scoping, settlement, reports, pretix links).
            $table->index('is_ledger');
            $table->index('event_id');
            $table->index('pretix_order_id');
            $table->index('instrument_type');
        });

        // Backfill from the existing event codes (same groups as the model).
        foreach (['T04', 'T20', 'T21'] as $prefix) {
            DB::table('transactions')
                ->where('transaction_event_code', 'like', $prefix . '%')
                ->update(['is_ledger' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['is_ledger']);
            $table->dropIndex(['event_id']);
            $table->dropIndex(['pretix_order_id']);
            $table->dropIndex(['instrument_type']);
            $table->dropColumn('is_ledger');
        });
    }
};
