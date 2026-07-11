<?php

namespace App\Filament\Pages;

use App\Services\Reporting\ReportService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Two accountant-facing views over a date range:
 *  - Auszahlungs-Abgleich: the balance bridge PayPal -> bank (what came in net
 *    of fees/refunds, what was paid out, what should remain), plus the list of
 *    individual payouts.
 *  - Monatsabschluss: per-month revenue/fees/refunds/VAT summary, downloadable
 *    as CSV for the tax advisor.
 */
class FinancialCloseReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationLabel = 'Finanzabschluss';

    protected static ?string $title = 'Finanzabschluss & Auszahlungs-Abgleich';

    protected static ?string $navigationGroup = 'Berichte';

    protected static ?int $navigationSort = 20;

    protected static string $view = 'filament.pages.financial-close';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-reports') ?? false;
    }

    public function mount(): void
    {
        // Default to the current calendar year for a sensible first view.
        $this->form->fill([
            'from' => now()->startOfYear()->toDateString(),
            'until' => now()->toDateString(),
            'vat_rate' => 19,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Zeitraum')
                    ->columns(3)
                    ->schema([
                        Forms\Components\DatePicker::make('from')->label('Von')->live(),
                        Forms\Components\DatePicker::make('until')->label('Bis')->live(),
                        Forms\Components\TextInput::make('vat_rate')->label('MwSt-Satz (Fallback %)')
                            ->numeric()->default(19)->live()
                            ->helperText('Nur für Transaktionen ohne echte pretix-Steuer.'),
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

    private function vatRate(): float
    {
        return (float) ($this->data['vat_rate'] ?? 19);
    }

    public function getReconciliationProperty(): array
    {
        return app(ReportService::class)->payoutReconciliation($this->from(), $this->until());
    }

    public function getPayoutListProperty()
    {
        return app(ReportService::class)->payouts($this->from(), $this->until());
    }

    public function getMonthlyProperty()
    {
        return app(ReportService::class)->monthlyTaxSummary($this->from(), $this->until(), $this->vatRate());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadCsv')
                ->label('Monatsabschluss als CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(fn () => $this->streamCsv()),
        ];
    }

    private function streamCsv(): StreamedResponse
    {
        $rows = $this->monthly;
        $filename = 'monatsabschluss-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            // BOM so Excel opens UTF-8 (umlauts) correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Monat', 'Anzahl', 'Umsatz', 'Gebühren', 'Erstattungen', 'MwSt', 'Netto (ohne MwSt)'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['month'],
                    $r['count'],
                    number_format($r['gross'], 2, ',', '.'),
                    number_format($r['fee'], 2, ',', '.'),
                    number_format($r['refunds'], 2, ',', '.'),
                    number_format($r['vat'], 2, ',', '.'),
                    number_format($r['net_excl_vat'], 2, ',', '.'),
                ], ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
