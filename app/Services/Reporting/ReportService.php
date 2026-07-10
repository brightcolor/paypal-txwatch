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
            ->when($from, fn (Builder $q) => $q->whereDate('transaction_initiation_date', '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate('transaction_initiation_date', '<=', $to));
    }

    /**
     * @return Collection<int, array{label: string, count: int, gross: float, fee: float, net: float, fee_ratio: float}>
     */
    public function feesByEvent(?Carbon $from = null, ?Carbon $to = null): Collection
    {
        return $this->baseQuery($from, $to)
            ->with('event')
            ->get()
            ->groupBy(fn (Transaction $t) => $t->event?->displayName() ?? 'Ohne Event')
            ->map(fn (Collection $rows, string $label) => $this->summarize($label, $rows))
            ->values()
            ->sortByDesc('gross')
            ->values();
    }

    /**
     * @return Collection<int, array{label: string, count: int, gross: float, fee: float, net: float, fee_ratio: float}>
     */
    public function feesByMonth(?Carbon $from = null, ?Carbon $to = null): Collection
    {
        return $this->baseQuery($from, $to)
            ->get()
            ->groupBy(fn (Transaction $t) => $t->transaction_initiation_date?->format('Y-m') ?? 'Unbekannt')
            ->map(fn (Collection $rows, string $label) => $this->summarize($label, $rows))
            ->values()
            ->sortBy('label')
            ->values();
    }

    /**
     * @return Collection<int, array{label: string, count: int, gross: float, fee: float, net: float, fee_ratio: float}>
     */
    public function accountComparison(?Carbon $from = null, ?Carbon $to = null): Collection
    {
        return $this->baseQuery($from, $to)
            ->with('paypalAccount')
            ->get()
            ->groupBy(fn (Transaction $t) => $t->paypalAccount?->name ?? 'Unbekannt')
            ->map(fn (Collection $rows, string $label) => $this->summarize($label, $rows))
            ->values()
            ->sortByDesc('gross')
            ->values();
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
        $value = trim($customField);

        // Real-world custom_field values follow PayPal's "Order <prefix>-<order-id>"
        // scheme, e.g. "Order GAG-WISMAR-2026-SC3HR" -> prefix "GAG-WISMAR-2026".
        // The trailing order-id segment is alphanumeric (not necessarily digits-only,
        // e.g. "SC3HR"), so it can only be identified by position (last dash-separated
        // segment), not by character class.
        $value = preg_replace('/^order[:\s]+/i', '', $value);

        $segments = explode('-', $value);

        if (count($segments) > 1) {
            array_pop($segments);
            $prefix = trim(implode('-', $segments));

            if ($prefix !== '') {
                return $prefix;
            }
        }

        return $value !== '' ? $value : $customField;
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
        $refunds = $this->baseQuery($from, $to)
            ->whereIn('transaction_event_code', Transaction::REFUND_EVENT_CODES)
            ->get();

        return [
            'count' => $refunds->count(),
            'total' => (float) $refunds->sum(fn (Transaction $t) => (float) $t->gross_amount),
        ];
    }

    /**
     * @return array{label: string, count: int, gross: float, fee: float, net: float, fee_ratio: float}
     */
    private function summarize(string $label, Collection $rows): array
    {
        $gross = (float) $rows->sum(fn (Transaction $t) => (float) $t->gross_amount);
        $fee = (float) $rows->sum(fn (Transaction $t) => (float) $t->fee_amount);
        $net = (float) $rows->sum(fn (Transaction $t) => (float) $t->net_amount);

        return [
            'label' => $label,
            'count' => $rows->count(),
            'gross' => $gross,
            'fee' => $fee,
            'net' => $net,
            'fee_ratio' => $gross != 0 ? round(abs($fee / $gross) * 100, 2) : 0.0,
        ];
    }
}
