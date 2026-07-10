<?php

namespace App\Filament\Widgets;

use App\Models\Transaction;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class DashboardStatsOverview extends BaseWidget
{
    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $since = Carbon::now()->subDays(30);

        $base = Transaction::query()->excludingLedgerEvents()->excludingIrrelevant()->where('transaction_initiation_date', '>=', $since);

        $count = (clone $base)->count();
        $gross = (clone $base)->sum('gross_amount');
        $fees = (clone $base)->sum('fee_amount');
        $net = (clone $base)->sum('net_amount');
        $refunds = (clone $base)->whereIn('transaction_event_code', Transaction::REFUND_EVENT_CODES)->count();
        $avgBasket = $count > 0 ? $gross / $count : 0;
        $feeRatio = $gross != 0 ? abs($fees / $gross) * 100 : 0;
        $unassigned = (clone $base)->whereNull('event_id')->count();

        return [
            Stat::make('Transaktionen (30 Tage)', number_format($count, 0, ',', '.')),
            Stat::make('Bruttoumsatz', number_format($gross, 2, ',', '.') . ' €'),
            Stat::make('Gebühren', number_format($fees, 2, ',', '.') . ' € (' . number_format($feeRatio, 1) . '%)'),
            Stat::make('Netto', number_format($net, 2, ',', '.') . ' €'),
            Stat::make('Ø Warenkorb', number_format($avgBasket, 2, ',', '.') . ' €'),
            Stat::make('Rückzahlungen/Reversals', $refunds),
            Stat::make('Nicht zugeordnet', $unassigned)
                ->description('Transaktionen ohne Event')
                ->color($unassigned > 0 ? 'warning' : 'success'),
        ];
    }
}
