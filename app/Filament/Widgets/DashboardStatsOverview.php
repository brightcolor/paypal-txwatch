<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\TransactionResource;
use App\Models\Transaction;
use App\Support\DashboardRange;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * AdminLTE-style "small boxes" for the dashboard: colored KPI tiles with an
 * icon watermark and a "Mehr Infos" link that jumps straight into the
 * matching pre-filtered transactions list. The period comes from the
 * dashboard's Matomo-style range picker (page filters).
 */
class DashboardStatsOverview extends Widget
{
    use InteractsWithPageFilters;
    protected static string $view = 'filament.widgets.dashboard-small-boxes';

    protected static bool $isLazy = false;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        // 8 aggregate queries per dashboard hit adds up once the table grows;
        // 60s staleness is irrelevant for these KPIs. Key by the active customer
        // scope AND the selected period so neither leaks into the other.
        $scope = \App\Support\CustomerScope::activeCustomerId() ?? 'all';
        $range = DashboardRange::cacheKey($this->filters);

        return \Illuminate\Support\Facades\Cache::remember(
            "dashboard_small_boxes:{$scope}:{$range}",
            now()->addSeconds(60),
            fn () => $this->computeViewData(),
        );
    }

    private function computeViewData(): array
    {
        [$from, $until, $rangeLabel] = DashboardRange::resolve($this->filters);

        // Customer users see only their own customer's figures; only the
        // latest revision per PayPal transaction counts.
        $base = \App\Support\CustomerScope::transactions(
            Transaction::query()->excludingLedgerEvents()->excludingIrrelevant()->currentRevision()
        )
            ->when($from, fn ($q) => $q->where('transaction_initiation_date', '>=', $from))
            ->when($until, fn ($q) => $q->where('transaction_initiation_date', '<=', $until));

        $count = (clone $base)->count();
        $gross = (clone $base)->sum('gross_amount');
        $fees = (clone $base)->sum('fee_amount');
        $net = (clone $base)->sum('net_amount');
        $refunds = (clone $base)->refunds()->count();
        $avgBasket = $count > 0 ? $gross / $count : 0;
        $feeRatio = $gross != 0 ? abs($fees / $gross) * 100 : 0;
        $unassigned = (clone $base)->whereNull('event_id')->count();
        $mismatch = \App\Support\CustomerScope::transactions(Transaction::query()->currentRevision())
            ->where('reconciliation_status', Transaction::RECONCILIATION_MISMATCH)->count();

        $eur = fn ($v) => number_format($v, 2, ',', '.') . ' €';
        $url = fn (array $filters = []) => TransactionResource::getUrl('index', array_filter(['tableFilters' => $filters]));

        return ['boxes' => [
            ['label' => "Umsatz ({$rangeLabel})", 'value' => $eur($gross), 'color' => 'primary', 'icon' => 'heroicon-o-banknotes', 'url' => $url()],
            ['label' => "Transaktionen ({$rangeLabel})", 'value' => number_format($count, 0, ',', '.'), 'color' => 'info', 'icon' => 'heroicon-o-queue-list', 'url' => $url()],
            ['label' => 'Gebühren (' . number_format($feeRatio, 1, ',', '.') . ' %)', 'value' => $eur($fees), 'color' => 'warning', 'icon' => 'heroicon-o-receipt-percent', 'url' => $url()],
            ['label' => 'Nach Gebühren', 'value' => $eur($net), 'color' => 'success', 'icon' => 'heroicon-o-wallet', 'url' => $url()],
            ['label' => 'Ø Warenkorb', 'value' => $eur($avgBasket), 'color' => 'secondary', 'icon' => 'heroicon-o-shopping-cart', 'url' => null],
            ['label' => 'Rückzahlungen/Reversals', 'value' => number_format($refunds, 0, ',', '.'), 'color' => $refunds > 0 ? 'danger' : 'success', 'icon' => 'heroicon-o-arrow-uturn-left', 'url' => $url(['refunds_only' => ['isActive' => true]])],
            ['label' => 'Ohne Event', 'value' => number_format($unassigned, 0, ',', '.'), 'color' => $unassigned > 0 ? 'warning' : 'success', 'icon' => 'heroicon-o-tag', 'url' => $url(['is_assigned' => ['value' => '0']])],
            ['label' => 'pretix-Abweichungen', 'value' => number_format($mismatch, 0, ',', '.'), 'color' => $mismatch > 0 ? 'danger' : 'success', 'icon' => 'heroicon-o-scale', 'url' => $url(['reconciliation_status' => ['value' => Transaction::RECONCILIATION_MISMATCH]])],
        ]];
    }
}
