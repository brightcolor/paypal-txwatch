<?php

namespace App\Filament\Actions;

use App\Exports\TransactionsExport;
use App\Models\ExportHistory;
use App\Models\ExportTemplate;
use App\Services\Export\ExportColumns;
use App\Services\Export\ExportDataBuilder;
use App\Services\Export\PdfRenderer;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Header action on the Transactions list: exports exactly the currently
 * filtered/searched/sorted table result (never the whole table) as
 * PDF, CSV, or XLSX - either using a saved ExportTemplate or an ad-hoc
 * configuration (columns, grouping, customer/internal mode, PII masking).
 */
class ExportFilterAction
{
    public static function make(): Action
    {
        return Action::make('exportFilter')
            ->label('Exportieren')
            ->icon('heroicon-o-document-arrow-down')
            ->form([
                Forms\Components\Select::make('format')
                    ->label('Format')
                    ->options(['pdf' => 'PDF', 'csv' => 'CSV', 'xlsx' => 'Excel (XLSX)'])
                    ->default('pdf')
                    ->required(),

                Forms\Components\Select::make('event_id')
                    ->label('Event (Deckblatt mit pretix-Daten)')
                    // Customer users only see (and can only cover) their own
                    // events - the cover carries live revenue/attendance data.
                    ->options(fn () => \App\Support\CustomerScope::byCustomerId(
                        \App\Models\Event::query()->where('is_active', true)
                    )->orderBy('name')->get()->mapWithKeys(fn ($e) => [$e->id => $e->displayName()]))
                    ->searchable()
                    ->helperText('Wenn gewählt, wird der Export auf dieses Event eingegrenzt und das PDF erhält ein Deckblatt mit Event-Bild, Spielinfos und der Gästebilanz (gebucht vs. erschienen) live aus pretix.'),

                Forms\Components\Select::make('export_template_id')
                    ->label('Export-Vorlage (optional)')
                    ->options(fn () => ExportTemplate::query()->pluck('name', 'id'))
                    // The template flagged as default is preselected.
                    ->default(fn () => ExportTemplate::defaultTemplate()?->id)
                    ->helperText('Wenn gewählt, werden Spalten/Layout/Farbe der Vorlage verwendet. Die Standard-Vorlage ist vorausgewählt.')
                    ->live(),

                Forms\Components\ColorPicker::make('accent_color')
                    ->label('Akzentfarbe (optional)')
                    ->helperText('Überschreibt die Farbe der Vorlage nur für diesen Export. Leer = Vorlagen-/Standardfarbe.'),

                Forms\Components\TextInput::make('vat_rate')
                    ->label('MwSt-Satz')
                    ->helperText('Fallback für Transaktionen ohne pretix-Verknüpfung; verknüpfte nutzen die echte MwSt aus pretix. Brutto gilt als MwSt-inklusive.')
                    ->numeric()
                    ->suffix('%')
                    ->default(19)
                    ->minValue(0)
                    ->maxValue(100)
                    ->required(),

                Forms\Components\Section::make('Ad-hoc-Konfiguration')
                    ->visible(fn (Forms\Get $get) => blank($get('export_template_id')))
                    ->schema([
                        Forms\Components\Repeater::make('columns')
                            ->label('Spalten (Reihenfolge per Drag & Drop)')
                            ->simple(
                                Forms\Components\Select::make('column')->options(ExportColumns::LABELS)->required(),
                            )
                            ->default(ExportTemplate::DEFAULT_COLUMNS)
                            ->reorderable()
                            ->addActionLabel('Spalte hinzufügen'),
                        Forms\Components\Select::make('mode')
                            ->label('Modus')
                            ->options([
                                ExportTemplate::MODE_CUSTOMER => 'Kunde (reduziert)',
                                ExportTemplate::MODE_INTERNAL => 'Intern (technisch)',
                            ])
                            ->default(ExportTemplate::MODE_CUSTOMER),
                        Forms\Components\Select::make('group_by')
                            ->label('Gruppieren nach')
                            ->options([
                                '' => 'Keine Gruppierung',
                                'event' => 'Event',
                                'day' => 'Tag',
                                'week' => 'Woche',
                                'month' => 'Monat',
                                'status' => 'Status',
                                'currency' => 'Währung',
                            ]),
                        Forms\Components\Toggle::make('mask_pii')->label('Namen/E-Mails maskieren'),
                        Forms\Components\TextInput::make('title')->label('Titel')->placeholder('Abrechnung {{ event.name }}'),
                        Forms\Components\TextInput::make('subtitle')->label('Untertitel'),
                        Forms\Components\Textarea::make('description')->label('Beschreibung'),
                    ]),

                Forms\Components\TextInput::make('filename_pattern')
                    ->label('Dateiname (optional)')
                    ->placeholder('Abrechnung {{ event.name }} {{ period.to }}')
                    ->helperText('Platzhalter erlaubt, z. B. {{ event.name }}, {{ period.to }}, {{ date }}. Endung wird automatisch angehängt. Bei gewählter Vorlage gilt deren Dateiname, falls hier leer.'),

                Forms\Components\Placeholder::make('placeholder_help')
                    ->label('Verfügbare Platzhalter')
                    ->content(fn () => new \Illuminate\Support\HtmlString(
                        '<div style="font-size:.78rem; line-height:1.6; columns:2; column-gap:1.5rem;">'
                        . collect(\App\Services\Export\ExportPlaceholders::available())
                            ->map(fn (string $d, string $k) => '<code>{{ ' . $k . ' }}</code> – ' . e($d))->implode('<br>')
                        . '</div>'
                    )),
            ])
            ->action(function (array $data, $livewire) {
                $query = $livewire->getTableQueryForExport();

                // Explicit event choice narrows the export to that event and
                // drives the pretix cover page. Resolved through CustomerScope
                // so a tampered request can never cover a foreign event.
                $coverEvent = filled($data['event_id'] ?? null)
                    ? \App\Support\CustomerScope::byCustomerId(\App\Models\Event::query())->find($data['event_id'])
                    : null;

                if ($coverEvent) {
                    $query->where('event_id', $coverEvent->id);
                }

                $template = filled($data['export_template_id'] ?? null)
                    ? ExportTemplate::find($data['export_template_id'])
                    : null;

                // Live pretix cover data (image + Spielinfos + Gästebilanz),
                // resolved BEFORE the build so its figures are also available as
                // placeholders. Fault-tolerant: on API problems the PDF just
                // renders the plain local cover.
                $pretixCover = $coverEvent
                    ? app(\App\Services\Pretix\PretixEventCover::class)->forEvent($coverEvent)
                    : null;

                // vat_rate is always taken from the dialog (default 19) so the rate is
                // definable per export and overrides any rate stored on the template.
                $overrides = ($template ? [] : [
                    'columns' => $data['columns'] ?? ExportTemplate::DEFAULT_COLUMNS,
                    'mode' => $data['mode'] ?? ExportTemplate::MODE_CUSTOMER,
                    'group_by' => filled($data['group_by'] ?? null) ? $data['group_by'] : null,
                    'mask_pii' => (bool) ($data['mask_pii'] ?? false),
                    'title' => $data['title'] ?? null,
                    'subtitle' => $data['subtitle'] ?? null,
                    'description' => $data['description'] ?? null,
                    'filename_pattern' => $data['filename_pattern'] ?? null,
                ]) + ['vat_rate' => (float) ($data['vat_rate'] ?? 19)]
                    + (filled($data['accent_color'] ?? null) ? ['accent_color' => $data['accent_color']] : [])
                    + ($coverEvent ? ['event' => $coverEvent, 'pretix_cover' => $pretixCover] : []);

                $built = app(ExportDataBuilder::class)->build($query, $template, $overrides);

                $format = $data['format'];

                // Storage path stays unique; the download name honours the
                // template's/ad-hoc filename pattern (with placeholders).
                $path = 'exports/export-' . now()->format('Ymd-His') . '-' . uniqid() . '.' . $format;
                $downloadName = \App\Services\Export\ExportPlaceholders::filename(
                    $built['filename_pattern'] ?? ($data['filename_pattern'] ?? null),
                    $built['placeholder_context'] ?? [],
                    $format,
                    'Export ' . now()->format('Y-m-d'),
                );

                if ($format === 'pdf') {
                    $content = app(PdfRenderer::class)->render($built);
                    Storage::disk('local')->put($path, $content);
                } else {
                    $writerType = $format === 'xlsx' ? ExcelFormat::XLSX : ExcelFormat::CSV;
                    Excel::store(new TransactionsExport($built), $path, 'local', $writerType);
                    $content = Storage::disk('local')->get($path);
                }

                ExportHistory::create([
                    'user_id' => auth()->id(),
                    'export_template_id' => $template?->id,
                    'format' => $format,
                    'filters_snapshot' => $livewire->tableFilters ?? [],
                    'file_path' => $path,
                    'row_count' => collect($built['groups'])->sum(fn ($g) => count($g['rows'])),
                    'expires_at' => now()->addDays(7),
                ]);

                Notification::make()
                    ->title('Export erstellt')
                    ->success()
                    ->send();

                return response()->streamDownload(fn () => print ($content), $downloadName);
            })
            ->modalHeading('Aktuellen Filter exportieren')
            ->modalSubmitActionLabel('Exportieren');
    }
}
