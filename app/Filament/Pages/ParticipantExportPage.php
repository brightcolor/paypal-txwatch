<?php

namespace App\Filament\Pages;

use App\Exports\ParticipantsExport;
use App\Models\Event;
use App\Models\PretixItem;
use App\Models\PretixOrder;
use App\Services\Pretix\ParticipantExporter;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Exports who bought which ticket type of an event.
 *
 * THE QUESTION IT ANSWERS: "all e-mail addresses of the Alex Christensen event with
 * VIP or Meet & Greet tickets". That was answerable nowhere - the ticket type lives
 * in the order positions, and nothing showed or filtered by it.
 *
 * THE COUNT IS SHOWN BEFORE THE DOWNLOAD, and that is not decoration: a mailing list
 * is acted on, and finding out afterwards that a filter matched three people instead
 * of three hundred is the expensive way round.
 *
 * SELECTING NO TICKET TYPE MEANS ALL OF THEM, stated on the field rather than left
 * to be guessed - an empty multi-select reads just as easily as "nothing".
 */
class ParticipantExportPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'pretix';

    protected static ?string $navigationLabel = 'Teilnehmer exportieren';

    protected static ?string $title = 'Teilnehmer exportieren';

    protected static ?int $navigationSort = 22;

    protected static ?string $slug = 'teilnehmer-export';

    protected static string $view = 'filament.pages.participant-export';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'mode' => ParticipantExporter::MODE_ADDRESSES,
            'columns' => self::wrapColumns(array_keys(ParticipantExporter::COLUMNS[ParticipantExporter::MODE_ADDRESSES])),
            // Paid only by default: an expired or cancelled order is not a guest, and
            // writing to those people is the mistake this default prevents.
            'statuses' => ['p'],
            'format' => 'csv',
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Auswahl')
                    ->columns(2)
                    ->schema([
                        Select::make('event_slug')
                            ->label('Veranstaltung')
                            ->options(fn () => Event::query()
                                ->whereNotNull('pretix_event_slug')
                                ->orderBy('name')
                                ->pluck('name', 'pretix_event_slug')
                                ->all())
                            ->searchable()
                            ->required()
                            // The ticket types belong to the event: changing it has to
                            // clear a selection that no longer exists.
                            ->afterStateUpdated(fn (callable $set) => $set('item_ids', []))
                            ->live(),

                        Select::make('item_ids')
                            ->label('Ticketarten')
                            ->multiple()
                            ->options(fn (callable $get) => PretixItem::optionsFor($get('event_slug')))
                            ->searchable()
                            ->helperText('Nichts ausgewählt = alle Ticketarten dieser Veranstaltung.')
                            ->live(),

                        Select::make('statuses')
                            ->label('Bestellstatus')
                            ->multiple()
                            ->options([
                                'p' => 'bezahlt',
                                'n' => 'offen',
                                'e' => 'abgelaufen',
                                'c' => 'storniert',
                            ])
                            ->helperText('Voreinstellung: nur bezahlte. Offene sind noch keine Gäste.')
                            ->live(),

                        Select::make('mode')
                            ->label('Inhalt')
                            ->options([
                                ParticipantExporter::MODE_ADDRESSES => 'E-Mail-Adressen (eine Zeile je Person)',
                                ParticipantExporter::MODE_PURCHASES => 'Käufe (eine Zeile je Ticket)',
                            ])
                            ->required()
                            // The two shapes have different columns; keeping the old
                            // selection would leave keys behind that this shape has no
                            // value for.
                            ->afterStateUpdated(fn (callable $set, $state) => $set(
                                'columns',
                                self::wrapColumns(array_keys(
                                    ParticipantExporter::COLUMNS[$state] ?? ParticipantExporter::COLUMNS[ParticipantExporter::MODE_ADDRESSES],
                                )),
                            ))
                            ->live(),

                        Select::make('format')
                            ->label('Format')
                            ->options([
                                'csv' => 'CSV (Komma, mit Kopfzeile)',
                                'txt' => 'Text (Tabulator, mit Kopfzeile)',
                                'xlsx' => 'Excel (XLSX)',
                            ])
                            ->helperText('Bei genau einer Spalte ergibt Text eine Datei mit einem Wert '
                                . 'je Zeile – zum Einfügen in ein Mailprogramm.')
                            ->required(),

                        /*
                         * SAME CONTROL AS THE TRANSACTION EXPORT: a repeater of simple
                         * selects, so the ORDER is chosen by dragging. A plain
                         * multi-select cannot express "e-mail first, name second", and
                         * for a list that gets pasted somewhere the order is half the
                         * point.
                         *
                         * The options follow the shape - an address row has no ticket
                         * price, and offering one would produce an empty column.
                         */
                        \Filament\Forms\Components\Repeater::make('columns')
                            ->label('Spalten (Reihenfolge per Drag & Drop)')
                            ->columnSpanFull()
                            // Not required: an empty row is dropped, and blocking the
                            // form over it would be worse than ignoring it.
                            ->simple(Select::make('column')->options(
                                fn (callable $get) => ParticipantExporter::COLUMNS[$get('../../mode')]
                                    ?? ParticipantExporter::COLUMNS[ParticipantExporter::MODE_ADDRESSES],
                            ))
                            ->live()
                            ->helperText('Nichts ausgewählt = alle Spalten dieser Form.'),

                        /*
                         * WHAT WOULD COME OUT, before anything is downloaded. A mailing
                         * list gets acted on; noticing afterwards that the filter caught
                         * three people instead of three hundred is the expensive order.
                         */
                        Placeholder::make('vorschau')
                            ->label('Ergebnis')
                            ->columnSpanFull()
                            ->content(fn (callable $get) => $this->preview($get)),
                    ]),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('export')
                ->label('Herunterladen')
                ->icon('heroicon-o-arrow-down-tray')
                ->action('export'),
        ];
    }

    public function export(): mixed
    {
        $data = $this->form->getState();

        $gebaut = app(ParticipantExporter::class)->build(
            (string) $data['event_slug'],
            $data['item_ids'] ?? [],
            $data['statuses'] ?? [],
            (string) $data['mode'],
            $data['columns'] ?? [],
        );

        if ($gebaut['count'] === 0) {
            // Refused rather than delivered empty: an empty file looks like a finished
            // export and gets mailed to nobody without anyone noticing.
            Notification::make()
                ->title('Nichts zu exportieren')
                ->body('Für diese Auswahl gibt es keine Zeilen. Prüfe Ticketart und Bestellstatus.')
                ->warning()
                ->send();

            return null;
        }

        $format = in_array($data['format'] ?? null, ['csv', 'txt', 'xlsx'], true) ? $data['format'] : 'csv';

        if ($format === 'txt') {
            $inhalt = ParticipantExporter::toText($gebaut);
        } else {
            $pfad = 'exports/teilnehmer-' . now()->format('Ymd-His') . '-' . uniqid() . '.' . $format;

            Excel::store(
                new ParticipantsExport($gebaut),
                $pfad,
                'local',
                $format === 'xlsx' ? ExcelFormat::XLSX : ExcelFormat::CSV,
            );

            $inhalt = \Illuminate\Support\Facades\Storage::disk('local')->get($pfad);
        }

        $name = sprintf(
            '%s-%s-%s.%s',
            $data['mode'] === ParticipantExporter::MODE_PURCHASES ? 'Kaeufe' : 'Adressen',
            \Illuminate\Support\Str::slug((string) $data['event_slug']),
            now()->format('Y-m-d'),
            $format,
        );

        Notification::make()
            ->title(sprintf('%d Zeilen exportiert', $gebaut['count']))
            ->success()
            ->send();

        return response()->streamDownload(fn () => print ($inhalt), $name);
    }

    /**
     * The repeater stores scalars; this is the shape it expects when set from code.
     *
     * @param  array<int, string>  $keys
     * @return array<int, string>
     */
    private static function wrapColumns(array $keys): array
    {
        return $keys;
    }

    /** A sentence saying what the current selection would produce. */
    private function preview(callable $get): string
    {
        $slug = $get('event_slug');

        if (blank($slug)) {
            return 'Bitte zuerst eine Veranstaltung wählen.';
        }

        if (PretixOrder::query()->where('event_slug', $slug)->doesntExist()) {
            return 'Zu dieser Veranstaltung sind keine Bestellungen importiert.';
        }

        $gebaut = app(ParticipantExporter::class)->build(
            (string) $slug,
            $get('item_ids') ?? [],
            $get('statuses') ?? [],
            (string) ($get('mode') ?? ParticipantExporter::MODE_ADDRESSES),
            $get('columns') ?? [],
        );

        if ($gebaut['count'] === 0) {
            return 'Keine Zeilen für diese Auswahl – prüfe Ticketart und Bestellstatus.';
        }

        return sprintf(
            '%d %s. Spalten: %s.',
            $gebaut['count'],
            $get('mode') === ParticipantExporter::MODE_PURCHASES ? 'Tickets' : 'E-Mail-Adressen (ohne Dubletten)',
            implode(', ', $gebaut['headings']),
        );
    }
}
