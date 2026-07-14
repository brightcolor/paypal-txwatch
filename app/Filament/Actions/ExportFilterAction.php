<?php

namespace App\Filament\Actions;

use App\Exports\TransactionsExport;
use App\Models\Event;
use App\Models\ExportHistory;
use App\Models\ExportTemplate;
use App\Services\Export\ExportColumns;
use App\Services\Export\ExportDataBuilder;
use App\Services\Export\ExportPlaceholders;
use App\Services\Export\PdfRenderer;
use App\Services\Pretix\PretixEventCover;
use App\Support\CustomerScope;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Header action on the Transactions list. A tabbed dialog:
 *  - "PDF": full styled report - template, event cover (live pretix), colours,
 *    title/subtitle/description, columns, filename. All settings stay editable
 *    even when a template is chosen (the template just pre-fills them).
 *  - "CSV / Daten": the raw rows only (no cover/event box), template + columns
 *    + filename.
 * Separate footer buttons export the tab's format (makeModalSubmitAction with a
 * format argument), so each mask maps to its own download.
 * Templates advertise which kinds they apply to (all/pdf/csv).
 */
class ExportFilterAction
{
    public static function make(): Action
    {
        return Action::make('exportFilter')
            ->label('Exportieren')
            ->icon('heroicon-o-document-arrow-down')
            ->modalHeading('Exportieren')
            ->modalWidth(\Filament\Support\Enums\MaxWidth::FourExtraLarge)
            ->form([
                Forms\Components\Tabs::make()->tabs([
                    Forms\Components\Tabs\Tab::make('PDF')
                        ->icon('heroicon-o-document-text')
                        ->schema(static::pdfSchema()),
                    Forms\Components\Tabs\Tab::make('CSV / Daten')
                        ->icon('heroicon-o-table-cells')
                        ->schema(static::csvSchema()),
                ]),
            ])
            // The default submit exports the PDF tab; extra footer buttons export
            // the data tab as CSV or XLSX. Each passes its format as an argument.
            ->modalSubmitAction(fn ($action) => $action->label('Als PDF exportieren')->icon('heroicon-o-document-text'))
            ->extraModalFooterActions(fn (Action $action) => [
                $action->makeModalSubmitAction('exportCsv', ['format' => 'csv'])
                    ->label('Als CSV exportieren')->icon('heroicon-o-table-cells')->color('success'),
                $action->makeModalSubmitAction('exportXlsx', ['format' => 'xlsx'])
                    ->label('Als Excel exportieren')->icon('heroicon-o-table-cells')->color('success'),
            ])
            ->action(fn (array $data, array $arguments, $livewire) => static::export($data, $arguments['format'] ?? 'pdf', $livewire));
    }

    /** @return array<int, \Filament\Forms\Components\Component> */
    private static function pdfSchema(): array
    {
        return [
            Forms\Components\Select::make('pdf_template_id')
                ->label('Vorlage')
                ->options(fn () => ExportTemplate::query()->forFormat('pdf')->pluck('name', 'id'))
                ->default(fn () => ExportTemplate::defaultForFormat('pdf')?->id)
                ->live()
                ->afterStateUpdated(fn ($state, Forms\Set $set) => static::applyTemplate($state, $set))
                ->helperText('Vorbelegung – alle Felder bleiben hier editierbar. Die Standard-Vorlage ist vorausgewählt.'),

            Forms\Components\Select::make('event_id')
                ->label('Event (Deckblatt mit pretix-Daten)')
                ->options(fn () => CustomerScope::byCustomerId(Event::query()->where('is_active', true))
                    ->orderBy('name')->get()->mapWithKeys(fn ($e) => [$e->id => $e->displayName()]))
                ->searchable()
                ->helperText('Grenzt den Export auf dieses Event ein und liefert das Deckblatt (Bild, Spielinfos, Gästebilanz live aus pretix). Nur PDF.'),

            Forms\Components\Grid::make(3)->schema([
                Forms\Components\ColorPicker::make('accent_color')->label('Akzentfarbe')
                    ->default(fn () => ExportTemplate::defaultForFormat('pdf')?->accent_color),
                Forms\Components\TextInput::make('vat_rate')->label('MwSt-Satz')->numeric()->suffix('%')
                    ->default(fn () => (float) (ExportTemplate::defaultForFormat('pdf')?->vat_rate ?? 19)),
                Forms\Components\Select::make('mode')->label('Modus')
                    ->options([ExportTemplate::MODE_CUSTOMER => 'Kunde (reduziert)', ExportTemplate::MODE_INTERNAL => 'Intern (technisch)'])
                    ->default(fn () => ExportTemplate::defaultForFormat('pdf')?->mode ?? ExportTemplate::MODE_CUSTOMER),
            ]),

            Forms\Components\TextInput::make('title')->label('Titel')
                ->default(fn () => ExportTemplate::defaultForFormat('pdf')?->title)
                ->placeholder('Abrechnung {{ event.name }}'),
            Forms\Components\TextInput::make('subtitle')->label('Untertitel')
                ->default(fn () => ExportTemplate::defaultForFormat('pdf')?->subtitle),
            Forms\Components\Textarea::make('description')->label('Beschreibung')->rows(2)
                ->default(fn () => ExportTemplate::defaultForFormat('pdf')?->description),

            Forms\Components\Select::make('group_by')->label('Gruppieren nach')
                ->options(['' => 'Keine Gruppierung', 'event' => 'Event', 'day' => 'Tag', 'week' => 'Woche', 'month' => 'Monat', 'status' => 'Status', 'currency' => 'Währung'])
                ->default(fn () => ExportTemplate::defaultForFormat('pdf')?->group_by),
            Forms\Components\Toggle::make('mask_pii')->label('Namen/E-Mails maskieren')
                ->default(fn () => (bool) (ExportTemplate::defaultForFormat('pdf')?->mask_pii)),

            static::columnsField('columns', 'pdf'),
            static::filenameField('filename_pattern', 'pdf'),
            static::placeholderHelp(),
        ];
    }

    /** @return array<int, \Filament\Forms\Components\Component> */
    private static function csvSchema(): array
    {
        return [
            Forms\Components\Placeholder::make('csv_hint')->label('')
                ->content('Reiner Datenexport der aktuell gefilterten Transaktionen – ohne Deckblatt/Event-Infos.'),

            Forms\Components\Select::make('csv_template_id')
                ->label('Vorlage')
                ->options(fn () => ExportTemplate::query()->forFormat('csv')->pluck('name', 'id'))
                ->default(fn () => ExportTemplate::defaultForFormat('csv')?->id)
                ->live()
                ->afterStateUpdated(fn ($state, Forms\Set $set) => static::applyTemplate($state, $set, 'csv_')),

            Forms\Components\Grid::make(2)->schema([
                Forms\Components\TextInput::make('csv_vat_rate')->label('MwSt-Satz')->numeric()->suffix('%')
                    ->default(fn () => (float) (ExportTemplate::defaultForFormat('csv')?->vat_rate ?? 19)),
                Forms\Components\Toggle::make('csv_mask_pii')->label('Namen/E-Mails maskieren')
                    ->default(fn () => (bool) (ExportTemplate::defaultForFormat('csv')?->mask_pii)),
            ]),

            static::columnsField('csv_columns', 'csv'),
            static::filenameField('csv_filename_pattern', 'csv'),
        ];
    }

    private static function columnsField(string $name, string $kind): Forms\Components\Repeater
    {
        $tpl = ExportTemplate::defaultForFormat($kind === 'csv' ? 'csv' : 'pdf');

        return Forms\Components\Repeater::make($name)
            ->label('Spalten (Reihenfolge per Drag & Drop)')
            // Not ->required(): a blank row is simply dropped (pluckColumns
            // filters it), and hard-requiring it would block the other tab.
            ->simple(Forms\Components\Select::make('column')->options(ExportColumns::LABELS))
            ->default($tpl?->columns ?? ExportTemplate::DEFAULT_COLUMNS)
            ->reorderable()
            ->addActionLabel('Spalte hinzufügen')
            ->collapsible();
    }

    private static function filenameField(string $name, string $kind): Forms\Components\TextInput
    {
        return Forms\Components\TextInput::make($name)
            ->label('Dateiname (ohne Endung)')
            ->default(fn () => ExportTemplate::defaultForFormat($kind === 'csv' ? 'csv' : 'pdf')?->filename_pattern)
            ->placeholder('Abrechnung {{ event.name }} {{ timestamp }}')
            ->helperText('Platzhalter erlaubt (siehe Liste). Leer = automatischer Name; Endung wird angehängt.');
    }

    private static function placeholderHelp(): Forms\Components\Placeholder
    {
        return Forms\Components\Placeholder::make('placeholder_help')
            ->label('Verfügbare Platzhalter')
            ->content(fn () => new \Illuminate\Support\HtmlString(
                '<div style="font-size:.75rem; line-height:1.55; columns:2; column-gap:1.5rem;">'
                . collect(ExportPlaceholders::available())
                    ->map(fn (string $d, string $k) => '<code>{{ ' . $k . ' }}</code> – ' . e($d))->implode('<br>')
                . '</div>'
            ));
    }

    /**
     * Wraps a flat column list (['a', 'b']) into the internal shape a
     * `->simple()` Repeater expects when set programmatically:
     * [uuid => ['column' => 'a'], ...]. Filament only auto-wraps on
     * afterStateHydrated (initial/->default), NOT on a later Set::set(), so
     * setting the flat array directly renders the right number of rows with
     * EMPTY selects. Mirrors Repeater's own hydration.
     *
     * @param  array<int, string>  $columns
     * @return array<string, array<string, string>>
     */
    private static function wrapColumns(array $columns): array
    {
        $items = [];
        foreach ($columns as $column) {
            $items[(string) \Illuminate\Support\Str::uuid()] = ['column' => $column];
        }

        return $items;
    }

    /** Pre-fills the editable fields from a chosen template (prefix for CSV tab). */
    private static function applyTemplate(mixed $state, Forms\Set $set, string $prefix = ''): void
    {
        $t = $state ? ExportTemplate::find($state) : null;

        if ($prefix === 'csv_') {
            $set('csv_columns', static::wrapColumns($t?->columns ?? ExportTemplate::DEFAULT_COLUMNS));
            $set('csv_mask_pii', (bool) $t?->mask_pii);
            $set('csv_vat_rate', (float) ($t?->vat_rate ?? 19));
            $set('csv_filename_pattern', $t?->filename_pattern);

            return;
        }

        $set('columns', static::wrapColumns($t?->columns ?? ExportTemplate::DEFAULT_COLUMNS));
        $set('mode', $t?->mode ?? ExportTemplate::MODE_CUSTOMER);
        $set('group_by', $t?->group_by);
        $set('mask_pii', (bool) $t?->mask_pii);
        $set('vat_rate', (float) ($t?->vat_rate ?? 19));
        $set('title', $t?->title);
        $set('subtitle', $t?->subtitle);
        $set('description', $t?->description);
        $set('accent_color', $t?->accent_color);
        $set('filename_pattern', $t?->filename_pattern);
    }

    /** Runs the actual export for the tab's format. */
    private static function export(array $data, string $format, $livewire): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $query = $livewire->getTableQueryForExport();

        if ($format === 'pdf') {
            $coverEvent = filled($data['event_id'] ?? null)
                ? CustomerScope::byCustomerId(Event::query())->find($data['event_id'])
                : null;

            if ($coverEvent) {
                $query->where('event_id', $coverEvent->id);
            }

            $pretixCover = $coverEvent ? app(PretixEventCover::class)->forEvent($coverEvent) : null;

            // Template still contributes the flags not exposed in the dialog
            // (show_group_sums, show_event_info, footer_note); the form fields
            // override everything on top of it.
            $template = filled($data['pdf_template_id'] ?? null) ? ExportTemplate::find($data['pdf_template_id']) : null;

            $overrides = [
                'columns' => static::pluckColumns($data['columns'] ?? null),
                'mode' => $data['mode'] ?? ExportTemplate::MODE_CUSTOMER,
                'group_by' => filled($data['group_by'] ?? null) ? $data['group_by'] : null,
                'mask_pii' => (bool) ($data['mask_pii'] ?? false),
                'title' => $data['title'] ?? null,
                'subtitle' => $data['subtitle'] ?? null,
                'description' => $data['description'] ?? null,
                'vat_rate' => (float) ($data['vat_rate'] ?? 19),
            ]
                + (filled($data['accent_color'] ?? null) ? ['accent_color' => $data['accent_color']] : [])
                + (filled($data['filename_pattern'] ?? null) ? ['filename_pattern' => $data['filename_pattern']] : [])
                + ($coverEvent ? ['event' => $coverEvent, 'pretix_cover' => $pretixCover] : []);

            $built = app(ExportDataBuilder::class)->build($query, $template, $overrides);
            $content = app(PdfRenderer::class)->render($built);
            $storagePath = 'exports/export-' . now()->format('Ymd-His') . '-' . uniqid() . '.pdf';
            Storage::disk('local')->put($storagePath, $content);

            return static::finish($content, $built, $format, $storagePath, $template, $livewire);
        }

        // CSV / XLSX: raw data only, no cover/event.
        $template = filled($data['csv_template_id'] ?? null) ? ExportTemplate::find($data['csv_template_id']) : null;

        $overrides = [
            'columns' => static::pluckColumns($data['csv_columns'] ?? null),
            // Raw data: internal mode so no columns are silently stripped.
            'mode' => ExportTemplate::MODE_INTERNAL,
            'group_by' => null,
            'mask_pii' => (bool) ($data['csv_mask_pii'] ?? false),
            'vat_rate' => (float) ($data['csv_vat_rate'] ?? 19),
        ] + (filled($data['csv_filename_pattern'] ?? null) ? ['filename_pattern' => $data['csv_filename_pattern']] : []);

        $built = app(ExportDataBuilder::class)->build($query, $template, $overrides);

        $storagePath = 'exports/export-' . now()->format('Ymd-His') . '-' . uniqid() . '.' . $format;
        $writerType = $format === 'xlsx' ? ExcelFormat::XLSX : ExcelFormat::CSV;
        Excel::store(new TransactionsExport($built), $storagePath, 'local', $writerType);
        $content = Storage::disk('local')->get($storagePath);

        return static::finish($content, $built, $format, $storagePath, $template, $livewire);
    }

    /** @param array<int, mixed>|null $columns */
    private static function pluckColumns(?array $columns): array
    {
        if (blank($columns)) {
            return ExportTemplate::DEFAULT_COLUMNS;
        }

        // Repeater ->simple() stores scalar values already; guard for the older
        // [{column: x}] shape just in case.
        return collect($columns)->map(fn ($c) => is_array($c) ? ($c['column'] ?? null) : $c)->filter()->values()->all();
    }

    private static function finish(string $content, array $built, string $format, string $storagePath, ?ExportTemplate $template, $livewire): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $downloadName = ExportPlaceholders::filename(
            $built['filename_pattern'] ?? null,
            $built['placeholder_context'] ?? [],
            $format,
            'Export ' . now()->format('Y-m-d'),
        );

        ExportHistory::create([
            'user_id' => auth()->id(),
            'export_template_id' => $template?->id,
            'format' => $format,
            'filters_snapshot' => $livewire->tableFilters ?? [],
            'file_path' => $storagePath,
            'row_count' => collect($built['groups'])->sum(fn ($g) => count($g['rows'])),
            'expires_at' => now()->addDays(7),
        ]);

        Notification::make()->title('Export erstellt')->success()->send();

        return response()->streamDownload(fn () => print ($content), $downloadName);
    }
}
