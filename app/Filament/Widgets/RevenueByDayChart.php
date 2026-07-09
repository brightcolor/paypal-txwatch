<?php

namespace App\Filament\Widgets;

use App\Models\Transaction;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RevenueByDayChart extends ChartWidget
{
    protected static ?string $heading = 'Umsatz nach Tag (30 Tage)';

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        $since = Carbon::now()->subDays(30)->startOfDay();

        $rows = Transaction::query()
            ->where('transaction_initiation_date', '>=', $since)
            ->selectRaw('DATE(transaction_initiation_date) as day, SUM(gross_amount) as gross')
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('gross', 'day');

        $labels = [];
        $data = [];

        for ($date = $since->clone(); $date->lte(Carbon::now()); $date->addDay()) {
            $key = $date->format('Y-m-d');
            $labels[] = $date->format('d.m.');
            $data[] = (float) ($rows[$key] ?? 0);
        }

        return [
            'datasets' => [[
                'label' => 'Bruttoumsatz',
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
