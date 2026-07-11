<?php

namespace App\Filament\Widgets;

use App\Models\Transaction;
use App\Support\CustomerScope;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Period-over-period comparison tiles: this calendar month's revenue and
 * transaction count against the previous month and the same month a year ago,
 * with the percentage delta and an up/down trend. Customer-scoped like the
 * rest of the dashboard.
 */
class ComparisonStatsWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $now = Carbon::now();

        $thisMonth = $this->revenue($now->copy()->startOfMonth(), $now->copy()->endOfMonth());
        $prevMonth = $this->revenue($now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth());
        $lastYear = $this->revenue($now->copy()->subYear()->startOfMonth(), $now->copy()->subYear()->endOfMonth());

        $countThis = $this->count($now->copy()->startOfMonth(), $now->copy()->endOfMonth());
        $countPrev = $this->count($now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth());

        return [
            $this->stat('Umsatz diesen Monat', $this->eur($thisMonth), $thisMonth, $prevMonth, 'zum Vormonat'),
            $this->stat('Umsatz ' . $now->translatedFormat('F'), $this->eur($thisMonth), $thisMonth, $lastYear, 'zum Vorjahresmonat'),
            $this->stat('Transaktionen diesen Monat', number_format($countThis, 0, ',', '.'), $countThis, $countPrev, 'zum Vormonat', money: false),
        ];
    }

    private function stat(string $label, string $value, float $current, float $baseline, string $vsLabel, bool $money = true): Stat
    {
        $delta = $baseline != 0.0 ? round(($current - $baseline) / abs($baseline) * 100, 1) : null;

        $desc = $delta === null
            ? 'kein Vergleichswert'
            : sprintf('%+.1f %% %s', $delta, $vsLabel);

        $up = $delta !== null && $delta >= 0;

        return Stat::make($label, $value)
            ->description($desc)
            ->descriptionIcon($delta === null ? null : ($up ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down'))
            ->color($delta === null ? 'gray' : ($up ? 'success' : 'danger'));
    }

    private function base(): Builder
    {
        return CustomerScope::transactions(
            Transaction::query()->excludingLedgerEvents()->excludingIrrelevant()
        );
    }

    private function revenue(Carbon $from, Carbon $to): float
    {
        return (float) $this->base()
            ->whereBetween('transaction_initiation_date', [$from, $to])
            ->sum('gross_amount');
    }

    private function count(Carbon $from, Carbon $to): int
    {
        return (int) $this->base()
            ->whereBetween('transaction_initiation_date', [$from, $to])
            ->count();
    }

    private function eur(float $v): string
    {
        return number_format($v, 2, ',', '.') . ' €';
    }
}
