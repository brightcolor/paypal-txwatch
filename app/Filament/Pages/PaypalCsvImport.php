<?php

namespace App\Filament\Pages;

use App\Models\PaypalAccount;
use App\Models\SyncRun;
use App\Services\Sync\CsvColumnGuesser;
use App\Services\Sync\CsvImportService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\File;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Fallback import path for when API/Transaction-Search permissions are
 * unavailable: upload a PayPal "Activity Download" CSV, map its columns
 * (auto-suggested), preview a few rows, then import through the exact
 * same normalize -> assign -> idempotent-upsert pipeline as the API sync.
 */
class PaypalCsvImport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-arrow-up';

    protected static ?string $navigationGroup = 'PayPal';

    protected static ?string $navigationLabel = 'CSV-Import';

    protected static ?string $title = 'PayPal CSV-Import';

    protected static string $view = 'filament.pages.paypal-csv-import';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    public array $headers = [];

    public array $previewRows = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage-paypal-accounts') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('import')
                ->label('Import starten')
                ->action('import')
                ->requiresConfirmation()
                ->modalDescription('Die Datei wird vollständig eingelesen und importiert. Bereits vorhandene Transaktionen werden anhand des Dedupe-Keys übersprungen, echte Änderungen als neue Revision angelegt.'),
        ];
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Datei')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('paypal_account_id')
                            ->label('PayPal-Konto')
                            ->options(fn () => PaypalAccount::query()->pluck('name', 'id'))
                            ->required(),
                        Forms\Components\FileUpload::make('csv_file')
                            ->label('PayPal Activity Download CSV')
                            ->disk('local')
                            ->directory('csv-imports')
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel'])
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                $this->readCsvPreview($state, $set);
                            }),
                    ]),

                Forms\Components\Section::make('Vorschau')
                    ->visible(fn () => filled($this->previewRows))
                    ->schema([
                        Forms\Components\Placeholder::make('preview')
                            ->label('')
                            ->content(fn () => view('filament.pages.partials.csv-preview', [
                                'headers' => $this->headers,
                                'rows' => $this->previewRows,
                            ])),
                    ]),

                Forms\Components\Section::make('Spaltenzuordnung')
                    ->visible(fn () => filled($this->headers))
                    ->description('Automatisch erkannte Zuordnung - bei Bedarf anpassen.')
                    ->columns(3)
                    ->schema(array_map(
                        fn (string $field, string $label) => Forms\Components\Select::make("mapping.{$field}")
                            ->label($label)
                            ->options(fn () => array_combine($this->headers, $this->headers))
                            ->searchable()
                            ->placeholder('– nicht vorhanden –'),
                        array_keys(self::FIELD_LABELS),
                        self::FIELD_LABELS,
                    )),
            ])
            ->statePath('data');
    }

    private const FIELD_LABELS = [
        'transaction_id' => 'Transaktions-ID *',
        'date' => 'Datum *',
        'time' => 'Uhrzeit',
        'gross' => 'Brutto *',
        'fee' => 'Gebühr',
        'net' => 'Netto',
        'currency' => 'Währung',
        'name' => 'Name',
        'email' => 'E-Mail',
        'status' => 'Status',
        'custom_field' => 'Custom Field / Custom Number',
        'invoice_id' => 'Invoice ID',
        'subject' => 'Betreff',
        'note' => 'Notiz',
    ];

    private function readCsvPreview(mixed $state, Forms\Set $set): void
    {
        $path = $state instanceof TemporaryUploadedFile ? $state->getRealPath() : null;

        if (! $path || ! File::exists($path)) {
            $this->headers = [];
            $this->previewRows = [];

            return;
        }

        $rows = self::parseCsvFile($path);
        $this->headers = $rows['headers'];
        $this->previewRows = array_slice($rows['rows'], 0, 5);

        $set('mapping', CsvColumnGuesser::guess($this->headers));
    }

    /**
     * @return array{headers: array<int,string>, rows: array<int, array<string,string>>}
     */
    public static function parseCsvFile(string $path): array
    {
        $handle = fopen($path, 'r');
        $headers = fgetcsv($handle, escape: '\\') ?: [];
        $rows = [];

        while (($row = fgetcsv($handle, escape: '\\')) !== false) {
            if (count($row) !== count($headers)) {
                continue;
            }

            $rows[] = array_combine($headers, $row);
        }

        fclose($handle);

        return ['headers' => $headers, 'rows' => $rows];
    }

    public function import(): void
    {
        $state = $this->form->getState();

        $account = PaypalAccount::findOrFail($state['paypal_account_id']);
        $uploaded = $state['csv_file'];
        $path = is_string($uploaded)
            ? \Illuminate\Support\Facades\Storage::disk('local')->path($uploaded)
            : $uploaded->getRealPath();

        $parsed = self::parseCsvFile($path);

        if (empty($parsed['rows'])) {
            Notification::make()->title('Keine Datenzeilen in der Datei gefunden.')->danger()->send();

            return;
        }

        $run = app(CsvImportService::class)->import(
            $account,
            $parsed['rows'],
            $state['mapping'] ?? [],
            auth()->user(),
        );

        Notification::make()
            ->title('CSV-Import abgeschlossen')
            ->body("Neu: {$run->imported_count} · Aktualisiert: {$run->updated_count} · Übersprungen: {$run->skipped_count} · Fehler: {$run->error_count}")
            ->success()
            ->send();

        $this->redirect(\App\Filament\Resources\SyncRunResource::getUrl('view', ['record' => $run]));
    }
}
