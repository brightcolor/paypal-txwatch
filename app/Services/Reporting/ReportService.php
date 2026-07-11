<?php

namespace App\Services\Reporting;

use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only analytics over the transactions table: fee analysis, custom
 * field prefix breakdown, event-assignment coverage, and per-account
 * comparison. Pure query logic (no view concerns) so it stays unit
 * testable independent of the Filament report page.
 */
class ReportService
{
    private function baseQuery(?Carbon $from, ?Carbon $to): Builder
    {
        return Transaction::query()
            ->excludingLedgerEvents()
            ->excludingIrrelevant()
            ->when($from, fn (Builder $q) => $q->whereDate('transaction_initiation_date', '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate('transaction_initiation_date', '<=', $to));
    }

    /**
     * @return Collection<int, array{label: string, count: int, gross: float, fee: float, net: float, fee_ratio: float}>
     */
    public function feesByEvent(?Carbon $from = null, ?Carbon $to = null): Collection
    {
        // Aggregated in SQL: the previous ->get()->groupBy() loaded every
        // transaction (incl. raw payloads) into PHP, which does not scale.
        return $this->aggregate(
            $this->baseQuery($from, $to)
                ->leftJoin('events', 'events.id', '=', 'transactions.event_id')
                ->selectRaw("COALESCE(NULLIF(events.display_name, ''), events.name, 'Ohne Event') as label")
                ->groupBy('label'),
        )->sortByDesc('gross')->values();
    }

    /**
     * @return Collection<int, array{label: string, count: int, gross: float, fee: float, net: float, fee_ratio: float}>
     */
    public function feesByMonth(?Carbon $from = null, ?Carbon $to = null): Collection
    {
        return $this->aggregate(
            $this->baseQuery($from, $to)
                ->selectRaw("COALESCE(to_char(transaction_initiation_date, 'YYYY-MM'), 'Unbekannt') as label")
                ->groupBy('label'),
            sqliteLabel: "COALESCE(strftime('%Y-%m', transaction_initiation_date), 'Unbekannt')",
        )->sortBy('label')->values();
    }

    /**
     * @return Collection<int, array{label: string, count: int, gross: float, fee: float, net: float, fee_ratio: float}>
     */
    public function accountComparison(?Carbon $from = null, ?Carbon $to = null): Collection
    {
        return $this->aggregate(
            $this->baseQuery($from, $to)
                ->leftJoin('paypal_accounts', 'paypal_accounts.id', '=', 'transactions.paypal_account_id')
                ->selectRaw("COALESCE(paypal_accounts.name, 'Unbekannt') as label")
                ->groupBy('label'),
        )->sortByDesc('gross')->values();
    }

    /**
     * Runs the shared count/sum aggregation on a query that already selected
     * and grouped a "label" expression. $sqliteLabel swaps a Postgres-only
     * label expression for the SQLite test database.
     *
     * @return Collection<int, array{label: string, count: int, gross: float, fee: float, net: float, fee_ratio: float}>
     */
    private function aggregate(Builder $query, ?string $sqliteLabel = null): Collection
    {
        if ($sqliteLabel !== null && $query->getConnection()->getDriverName() === 'sqlite') {
            $query->getQuery()->columns = [];
            $query->getQuery()->groups = [];
            $query->selectRaw("{$sqliteLabel} as label")->groupBy('label');
        }

        return $query
            ->selectRaw('count(*) as cnt')
            ->selectRaw('COALESCE(sum(gross_amount), 0) as gross')
            ->selectRaw('COALESCE(sum(fee_amount), 0) as fee')
            ->selectRaw('COALESCE(sum(net_amount), 0) as net')
            ->get()
            ->map(fn ($row) => [
                'label' => $row->label,
                'count' => (int) $row->cnt,
                'gross' => (float) $row->gross,
                'fee' => (float) $row->fee,
                'net' => (float) $row->net,
                'fee_ratio' => (float) $row->gross != 0.0 ? round(abs($row->fee / $row->gross) * 100, 2) : 0.0,
            ]);
    }

    /**
     * Groups by a heuristically-extracted "prefix" of the custom field, e.g.
     * "Order GAG-WISMAR-2026-SC3HR" and "Order GAG-WISMAR-2026-A1B2C" both
     * roll up under "GAG-WISMAR-2026".
     *
     * @return Collection<int, array{prefix: string, count: int, gross: float}>
     */
    public function customFieldPrefixes(?Carbon $from = null, ?Carbon $to = null, int $limit = 20): Collection
    {
        return $this->baseQuery($from, $to)
            ->whereNotNull('custom_field')
            ->where('custom_field', '<>', '')
            ->get(['custom_field', 'gross_amount'])
            ->groupBy(fn (Transaction $t) => self::extractPrefix($t->custom_field))
            ->map(fn (Collection $rows, string $prefix) => [
                'prefix' => $prefix,
                'count' => $rows->count(),
                'gross' => (float) $rows->sum(fn (Transaction $t) => (float) $t->gross_amount),
            ])
            ->sortByDesc('count')
            ->take($limit)
            ->values();
    }

    public static function extractPrefix(string $customField): string
    {
        // Single source of truth for the "Order <event>-<nr>" parsing.
        return \App\Services\CustomFieldParser::eventReference($customField) ?? $customField;
    }

    /**
     * @return array{total: int, assigned: int, unassigned: int, ratio: float}
     */
    public function eventAssignmentRatio(?Carbon $from = null, ?Carbon $to = null): array
    {
        $total = $this->baseQuery($from, $to)->count();
        $assigned = $this->baseQuery($from, $to)->whereNotNull('event_id')->count();

        return [
            'total' => $total,
            'assigned' => $assigned,
            'unassigned' => $total - $assigned,
            'ratio' => $total > 0 ? round($assigned / $total * 100, 1) : 0.0,
        ];
    }

    /**
     * @return array{count: int, total: float}
     */
    public function refundsSummary(?Carbon $from = null, ?Carbon $to = null): array
    {
        $row = $this->baseQuery($from, $to)
            ->refunds()
            ->selectRaw('count(*) as cnt, COALESCE(sum(gross_amount), 0) as total')
            ->first();

        return [
            'count' => (int) $row->cnt,
            'total' => (float) $row->total,
        ];
    }

}
