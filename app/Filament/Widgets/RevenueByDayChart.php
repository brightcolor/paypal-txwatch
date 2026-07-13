<?php

namespace App\Filament\Widgets;

use App\Models\Transaction;
use App\Support\CustomerScope;
use App\Support\DashboardRange;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Carbon;

/**
 * Revenue-over-time chart driven by the dashboard's period picker. Short
 * ranges (≤ ~3 months) plot per day; longer ranges automatically switch to
 * per-month bars so a year view stays readable. 'Gesamt' is bounded to the
 * oldest transaction.
 */
class RevenueByDayChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    // Keep the chart short so the whole dashboard stays on one screen.
    protected static ?string $maxHeight = '230px';

    // Directly under the KPI tiles.
    protected static ?int $sort = 2;

    public function getHeading(): string
    {
        [, , $label] = DashboardRange::resolve($this->filters);

        return "Umsatz ({$label})";
    }

    protected function getData(): array
    {
        [$from, $until] = DashboardRange::resolve($this->filters);
        $until ??= Carbon::now()->endOfDay();

        // 'Gesamt' has no lower bound - anchor it on the oldest transaction.
        if (! $from) {
            $oldest = CustomerScope::transactions(Transaction::query())
                ->min('transaction_initiation_date');
            $from = $oldest ? Carbon::parse($oldest)->startOfDay() : Carbon::now()->subDays(30)->startOfDay();
        }

        $monthly = $from->diffInDays($until) > 92;

        $rows = CustomerScope::transactions(
            Transaction::query()->excludingLedgerEvents()->excludingIrrelevant()->currentRevision()
        )
            ->whereBetween('transaction_initiation_date', [$from, $until])
            ->when(
                $monthly && \DB::connection()->getDriverName() === 'pgsql',
                fn ($q) => $q->selectRaw("to_char(transaction_initiation_date, 'YYYY-MM') as bucket, SUM(gross_amount) as gross"),
            )
            ->when(
                $monthly && \DB::connection()->getDriverName() !== 'pgsql',
                fn ($q) => $q->selectRaw("strftime('%Y-%m', transaction_initiation_date) as bucket, SUM(gross_amount) as gross"),
            )
            ->when(
                ! $monthly,
                fn ($q) => $q->selectRaw('DATE(transaction_initiation_date) as bucket, SUM(gross_amount) as gross'),
            )
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->pluck('gross', 'bucket');

        $labels = [];
        $data = [];

        if ($monthly) {
            for ($date = $from->copy()->startOfMonth(); $date->lte($until); $date->addMonth()) {
                $labels[] = $date->format('m/Y');
                $data[] = (float) ($rows[$date->format('Y-m')] ?? 0);
            }
        } else {
            for ($date = $from->copy(); $date->lte($until); $date->addDay()) {
                $labels[] = $date->format('d.m.');
                $data[] = (float) ($rows[$date->format('Y-m-d')] ?? 0);
            }
        }

        return [
            'datasets' => [[
                'label' => 'Umsatz',
                'data' => $data,
                'borderColor' => '#2563eb',
                'backgroundColor' => 'rgba(37, 99, 235, 0.15)',
                'fill' => true,
            ]],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
