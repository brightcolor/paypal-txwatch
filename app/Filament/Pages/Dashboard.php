<?php

namespace App\Filament\Pages;

use App\Support\DashboardRange;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;

/**
 * Dashboard with a Matomo-style period picker: preset ranges (today,
 * yesterday, last 7/30/90 days, this/last month, this/last year, all) plus a
 * custom from/until range. The selection reaches the widgets via Filament's
 * page-filter mechanism (InteractsWithPageFilters) and drives the KPI tiles
 * and the revenue chart.
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    public function filtersForm(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make()
                    ->columns(3)
                    ->schema([
                        Forms\Components\Select::make('range')
                            ->label('Zeitraum')
                            ->options(DashboardRange::PRESETS)
                            ->default(DashboardRange::DEFAULT)
                            ->selectablePlaceholder(false)
                            ->native(false)
                            ->live(),
                        Forms\Components\DatePicker::make('from')
                            ->label('Von')
                            ->visible(fn (Forms\Get $get) => $get('range') === 'custom')
                            ->live(),
                        Forms\Components\DatePicker::make('until')
                            ->label('Bis')
                            ->visible(fn (Forms\Get $get) => $get('range') === 'custom')
                            ->live(),
                    ]),
            ]);
    }
}
