<?php

namespace App\Filament\Pages;

use App\Services\Reporting\ReportService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * Analytics beyond the Dashboard's headline KPIs: fee analysis by
 * event/month/account, custom-field prefix breakdown, and event-assignment
 * coverage. All figures respect the optional date-range filter above them.
 */
class Reports extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationLabel = 'Berichte';

    protected static ?string $title = 'Berichte';

    protected static ?string $navigationGroup = 'Berichte';

    protected static string $view = 'filament.pages.reports';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-reports') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Zeitraum')
                    ->columns(2)
                    ->schema([
                        Forms\Components\DatePicker::make('from')->label('Von')->live(),
                        Forms\Components\DatePicker::make('until')->label('Bis')->live(),
                    ]),
            ])
            ->statePath('data');
    }

    private function from(): ?Carbon
    {
        return filled($this->data['from'] ?? null) ? Carbon::parse($this->data['from']) : null;
    }

    private function until(): ?Carbon
    {
        return filled($this->data['until'] ?? null) ? Carbon::parse($this->data['until']) : null;
    }

    public function getFeesByEventProperty()
    {
        return app(ReportService::class)->feesByEvent($this->from(), $this->until());
    }

    public function getFeesByMonthProperty()
    {
        return app(ReportService::class)->feesByMonth($this->from(), $this->until());
    }

    public function getAccountComparisonProperty()
    {
        return app(ReportService::class)->accountComparison($this->from(), $this->until());
    }

    public function getCustomFieldPrefixesProperty()
    {
        return app(ReportService::class)->customFieldPrefixes($this->from(), $this->until());
    }

    public function getAssignmentRatioProperty()
    {
        return app(ReportService::class)->eventAssignmentRatio($this->from(), $this->until());
    }

    public function getRefundsSummaryProperty()
    {
        return app(ReportService::class)->refundsSummary($this->from(), $this->until());
    }
}
