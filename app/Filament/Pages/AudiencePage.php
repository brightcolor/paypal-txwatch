<?php

namespace App\Filament\Pages;

use App\Exports\AudienceBuyersExport;
use App\Exports\AudienceOverlapExport;
use App\Models\Event;
use App\Models\PretixItem;
use App\Models\PretixPosition;
use App\Services\Audience\AudienceBuyers;
use App\Services\Audience\AudienceDimensions;
use App\Services\Audience\AudienceOverlap;
use App\Services\Audience\AudienceQuery;
use App\Services\Audience\AudienceStats;
use App\Support\CustomerScope;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Who comes to which events - and what that says about the programme.
 *
 * THE SELECTION IS THE SCREEN: pick a few events, and everything below answers for
 * exactly those. Every figure is built by the Audience services, so this page holds
 * no arithmetic of its own - and no way past the customer scope that sits in
 * AudienceQuery.
 */
class AudiencePage extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Berichte';

    protected static ?string $navigationLabel = 'Publikum';

    protected static ?string $title = 'Publikum';

    protected static ?int $navigationSort = 12;

    protected static ?string $slug = 'publikum';

    protected static string $view = 'filament.pages.audience';

    public const STATUS_OPTIONS = [
        'p' => 'bezahlt',
        'n' => 'offen',
        'e' => 'abgelaufen',
        'c' => 'storniert',
    ];

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-audience') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            // Every visible event: the question is "who was where", and that only
            // gets an answer across several events.
            'event_slugs' => array_keys($this->eventOptions()),
            'statuses' => ['p'],
            'item_ids' => [],
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Auswahl')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('event_slugs')
                            ->label('Veranstaltungen')
                            ->multiple()
                            ->options(fn () => $this->eventOptions())
                            ->searchable()
                            ->helperText('Nichts ausgewählt = alle sichtbaren Veranstaltungen.')
                            // The ticket types belong to the events: a changed event
                            // list has to clear a selection that may no longer exist.
                            ->afterStateUpdated(fn (callable $set) => $set('item_ids', []))
                            ->live(),

                        Forms\Components\Select::make('statuses')
                            ->label('Bestellstatus')
                            ->multiple()
                            ->options(self::STATUS_OPTIONS)
                            ->helperText('Voreinstellung: nur bezahlte Bestellungen.')
                            ->live(),

                        Forms\Components\Select::make('item_ids')
                            ->label('Ticketarten')
                            ->multiple()
                            ->options(fn (callable $get) => $this->itemOptions($get('event_slugs') ?? []))
                            ->searchable()
                            ->helperText('Nichts ausgewählt = alle Ticketarten.')
                            ->live(),

                        Forms\Components\Group::make([
                            Forms\Components\DatePicker::make('from')->label('Bestellt ab')->live(),
                            Forms\Components\DatePicker::make('until')->label('Bestellt bis')->live(),
                        ])->columns(2),
                    ]),
            ])
            ->statePath('data');
    }

    /** The current selection, as the services want it. */
    public function currentQuery(): AudienceQuery
    {
        return new AudienceQuery(
            eventSlugs: $this->data['event_slugs'] ?? [],
            statuses: $this->data['statuses'] ?? [],
            itemIds: $this->data['item_ids'] ?? [],
            from: filled($this->data['from'] ?? null) ? Carbon::parse($this->data['from']) : null,
            until: filled($this->data['until'] ?? null) ? Carbon::parse($this->data['until']) : null,
        );
    }

    public function getStatsProperty(): array
    {
        return app(AudienceStats::class)->forSelection($this->currentQuery());
    }

    public function getOverlapProperty(): array
    {
        return app(AudienceOverlap::class)->matrix($this->currentQuery());
    }

    public function getFirstTimeProperty(): array
    {
        return app(AudienceOverlap::class)->firstTimeByEvent($this->currentQuery());
    }

    public function getDimensionsProperty(): AudienceDimensions
    {
        return app(AudienceDimensions::class);
    }

    /**
     * The buyer list.
     *
     * A REAL TABLE rather than a rendered array: this is the list people work in -
     * sort by tickets, search for a name, page through it, take it away as a file.
     * All of that is free here and hand-built anywhere else.
     */
    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => app(AudienceBuyers::class)->query($this->currentQuery()))
            ->defaultSort('tickets', 'desc')
            /*
             * 200 IS THE CEILING HERE, below the global policy's 500: this query
             * groups over every position of the selection, so a large page repeats
             * that work on every reload. Because the option does not exist, nothing
             * has to be clamped back afterwards.
             */
            ->paginated([25, 50, 100, 200])
            ->defaultPaginationPageOption(50)
            /*
             * NO KEY SORT, and on purpose: it would append ORDER BY pretix_positions.id,
             * a column outside the GROUP BY. SQLite accepts that, PostgreSQL refuses
             * the query. Off by default in Filament 3; stated here so an upgrade that
             * flips the default meets AudienceBuyerTableTest first.
             */
            ->defaultKeySort(false)
            ->heading('Käufer')
            ->description('Eine Zeile je E-Mail-Adresse, über die gewählten Veranstaltungen hinweg.')
            ->columns([
                Tables\Columns\TextColumn::make('buyer_email')
                    ->label('E-Mail')
                    ->searchable(query: fn ($query, string $search) => AudienceBuyers::search($query, $search))
                    ->copyable(),

                Tables\Columns\TextColumn::make('buyer_display_name')
                    ->label('Name')
                    ->placeholder('ohne Angabe'),

                Tables\Columns\TextColumn::make('events')
                    ->label('Veranstaltungen')
                    ->sortable()
                    ->alignEnd()
                    // $state and $record by name: Filament injects closure arguments
                    // by their parameter name, and a different name gives a 500.
                    ->tooltip(fn ($record) => implode(', ', app(AudienceBuyers::class)
                        ->eventNames($this->currentQuery(), (string) $record->buyer_email))),

                Tables\Columns\TextColumn::make('orders')
                    ->label('Bestellungen')
                    ->sortable()
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('tickets')
                    ->label('Tickets')
                    ->sortable()
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('revenue')
                    ->label('Umsatz')
                    ->sortable()
                    ->alignEnd()
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2, ',', '.') . ' €'),

                Tables\Columns\TextColumn::make('first_at')
                    ->label('Erste Bestellung')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => $state ? Carbon::parse($state)->format('d.m.Y') : ''),

                Tables\Columns\TextColumn::make('last_at')
                    ->label('Letzte Bestellung')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => $state ? Carbon::parse($state)->format('d.m.Y') : ''),
            ])
            ->headerActions([
                Tables\Actions\Action::make('kaeuferliste_csv')
                    ->label('Käuferliste als CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(fn () => $this->downloadBuyers('csv')),

                Tables\Actions\Action::make('kaeuferliste_xlsx')
                    ->label('Käuferliste als Excel')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(fn () => $this->downloadBuyers('xlsx')),

                Tables\Actions\Action::make('ueberschneidung_xlsx')
                    ->label('Überschneidung als Excel')
                    ->icon('heroicon-o-table-cells')
                    ->color('gray')
                    ->action(fn () => $this->downloadOverlap()),
            ])
            ->emptyStateHeading('Keine Käufer in dieser Auswahl')
            ->emptyStateDescription('Prüfe die gewählten Veranstaltungen, den Bestellstatus und den Zeitraum. Ohne pretix-Import liegen noch keine Bestellungen vor.');
    }

    public function downloadBuyers(string $format): ?StreamedResponse
    {
        $export = new AudienceBuyersExport($this->currentQuery());
        $anzahl = count($export->array());

        if ($anzahl === 0) {
            return $this->refuse();
        }

        $format = $format === 'xlsx' ? 'xlsx' : 'csv';

        return $this->deliver($export, $format, 'publikum-kaeufer', $anzahl . ' Käufer exportiert');
    }

    public function downloadOverlap(): ?StreamedResponse
    {
        $export = new AudienceOverlapExport($this->currentQuery());
        $anzahl = count($export->array());

        if ($anzahl === 0) {
            return $this->refuse();
        }

        return $this->deliver($export, 'xlsx', 'publikum-ueberschneidung', $anzahl . ' Veranstaltungen exportiert');
    }

    /**
     * Refused rather than delivered empty: an empty file looks like a finished
     * export, and nobody notices until someone opens it.
     */
    private function refuse(): null
    {
        Notification::make()
            ->title('Nichts zu exportieren')
            ->body('Für diese Auswahl gibt es keine Käufer. Prüfe die gewählten Veranstaltungen, den Bestellstatus und den Zeitraum.')
            ->warning()
            ->send();

        return null;
    }

    /**
     * Rendered in memory and streamed.
     *
     * NOTHING IS WRITTEN TO DISK: the file holds names and e-mail addresses, and a
     * copy under storage/ would outlive the download with nobody to delete it.
     */
    private function deliver(object $export, string $format, string $name, string $meldung): StreamedResponse
    {
        $inhalt = (string) Excel::raw($export, $format === 'xlsx' ? ExcelFormat::XLSX : ExcelFormat::CSV);

        Notification::make()->title($meldung)->success()->send();

        return response()->streamDownload(
            fn () => print ($inhalt),
            $name . '-' . now()->format('Y-m-d') . '.' . $format,
        );
    }

    /**
     * The readable name of an event, for headings and matrix labels.
     *
     * Reads the plain name rather than the picker label: the picker marks events
     * without orders, and that marking has no business in a table header.
     */
    public function eventLabel(string $slug): string
    {
        return Event::namesBySlug()[$slug] ?? $slug;
    }

    /**
     * The events that can be chosen, with a hint where nothing was sold yet.
     *
     * AN EVENT WITHOUT ORDERS STAYS IN THE LIST: it is set up, it simply has no
     * tickets yet, and leaving it out would look like it was never configured. The
     * hint is what keeps someone from reading an empty result as a defect.
     *
     * @return array<string, string>
     */
    private function eventOptions(): array
    {
        $events = CustomerScope::byEventSlug(
            Event::query()->whereNotNull('pretix_event_slug'),
            'pretix_event_slug',
        )->pluck('name', 'pretix_event_slug');

        $mitBestellungen = PretixPosition::query()
            ->whereIn('event_slug', $events->keys()->all())
            ->distinct()
            ->pluck('event_slug')
            ->all();

        $optionen = [];

        foreach ($events as $slug => $name) {
            $optionen[$slug] = in_array($slug, $mitBestellungen, true)
                ? (string) $name
                : $name . ' (noch keine Bestellungen)';
        }

        asort($optionen);

        return $optionen;
    }

    /**
     * @param  array<int, string>  $slugs
     * @return array<int, string>
     */
    private function itemOptions(array $slugs): array
    {
        $erlaubt = $slugs !== [] ? $slugs : array_keys($this->eventOptions());

        return PretixItem::query()
            ->whereIn('event_slug', $erlaubt)
            ->orderBy('name')
            ->pluck('name', 'item_id')
            ->all();
    }
}
