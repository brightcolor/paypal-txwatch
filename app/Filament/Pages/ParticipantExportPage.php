<?php

namespace App\Filament\Pages;

use App\Exports\ParticipantsExport;
use App\Models\Event;
use App\Models\PretixItem;
use App\Models\PretixOrder;
use App\Services\Pretix\ParticipantExporter;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
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
 * THE JOB BEHIND IT: some ticket holders have to be told something in advance -
 * "everyone with a VIP or Meet & Greet ticket". So the addresses are the point, and
 * everything else is bookkeeping around them.
 *
 * TWO TABS, and the split follows that. The first does one thing: pick event, pick
 * ticket types, get the addresses - shown ON SCREEN, ready to copy into a mail
 * client, because the next step is almost never "open a file" but "paste into BCC".
 * The second is the full export with columns and formats, for the rarer case of
 * counting and checking.
 *
 * THE COUNT IS SHOWN BEFORE THE DOWNLOAD, and that is not decoration: a mailing list
 * is acted on, and finding out afterwards that a filter matched three people instead
 * of three hundred is the expensive way round.
 */
class ParticipantExportPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'pretix';

    protected static ?string $navigationLabel = 'Teilnehmer & Adressen';

    protected static ?string $title = 'Teilnehmer & Adressen';

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
            // Paid only by default: an expired or cancelled order is not a guest, and
            // writing to those people is the mistake this default prevents.
            'mail_statuses' => ['p'],
            'mail_separator' => 'semicolon',

            'mode' => ParticipantExporter::MODE_ADDRESSES,
            'columns' => array_keys(ParticipantExporter::COLUMNS[ParticipantExporter::MODE_ADDRESSES]),
            'statuses' => ['p'],
            'format' => 'csv',
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Tabs::make('export')
                    ->tabs([
                        Tabs\Tab::make('E-Mail-Adressen')
                            ->icon('heroicon-o-envelope')
                            ->schema($this->mailTab()),

                        Tabs\Tab::make('Ausführlicher Export')
                            ->icon('heroicon-o-table-cells')
                            ->schema($this->fullTab()),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * The tab that does the actual job: addresses, ready to paste.
     *
     * @return array<int, \Filament\Forms\Components\Component>
     */
    private function mailTab(): array
    {
        return [
            Select::make('mail_event_slug')
                ->label('Veranstaltung')
                ->options(fn () => $this->eventOptions())
                ->searchable()
                ->required()
                // The ticket types belong to the event: changing it has to clear a
                // selection that no longer exists.
                ->afterStateUpdated(function (callable $get, callable $set) {
                    $set('mail_item_ids', []);
                    $this->refreshMailList($get, $set);
                })
                ->live(),

            Select::make('mail_item_ids')
                ->label('Ticketarten')
                ->multiple()
                ->options(fn (callable $get) => PretixItem::optionsFor($get('mail_event_slug')))
                ->searchable()
                ->helperText('Nichts ausgewählt = alle Ticketarten dieser Veranstaltung.')
                ->afterStateUpdated(fn (callable $get, callable $set) => $this->refreshMailList($get, $set))
                ->live(),

            Select::make('mail_statuses')
                ->label('Bestellstatus')
                ->multiple()
                ->options(self::STATUS_OPTIONS)
                ->helperText('Voreinstellung: nur bezahlte. Offene Bestellungen sind noch keine Gäste.')
                ->afterStateUpdated(fn (callable $get, callable $set) => $this->refreshMailList($get, $set))
                ->live(),

            Select::make('mail_separator')
                ->label('Trennzeichen')
                ->options([
                    'semicolon' => 'Semikolon – für das BCC-Feld im Mailprogramm',
                    'newline' => 'Zeilenumbruch – eine Adresse je Zeile',
                    'comma' => 'Komma',
                ])
                ->required()
                ->afterStateUpdated(fn (callable $get, callable $set) => $this->refreshMailList($get, $set))
                ->live(),

            Placeholder::make('mail_anzahl')
                ->label('Empfänger')
                ->content(fn (callable $get) => $this->mailSummary($get)),

            /*
             * ON SCREEN AND COPIED BY A CLICK, not only as a file. The next step after
             * choosing the ticket types is almost never "open a download" but "paste
             * into BCC" - a round trip through the file system for that is a detour
             * with nothing at the end of it, and marking the text by hand first is one
             * handle too many for the single thing this field is for.
             *
             * A VIEW FIELD rather than a Textarea, because the click handler and the
             * confirmation belong to the same element: a clipboard is invisible, and
             * without an answer nobody knows whether the click did anything.
             */
            \Filament\Forms\Components\ViewField::make('mail_liste')
                ->label('Adressen')
                ->view('filament.forms.copy-addresses')
                ->columnSpanFull()
                ->dehydrated(false)
                ->live(),

            Actions::make([
                FormAction::make('mail_txt')
                    ->label('Als Text herunterladen')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(fn () => $this->exportMails('txt')),

                FormAction::make('mail_csv')
                    ->label('Als CSV herunterladen')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(fn () => $this->exportMails('csv')),
            ])->columnSpanFull(),
        ];
    }

    /**
     * The full export: any shape, any columns, any format.
     *
     * @return array<int, \Filament\Forms\Components\Component>
     */
    private function fullTab(): array
    {
        return [
            Select::make('event_slug')
                ->label('Veranstaltung')
                ->options(fn () => $this->eventOptions())
                ->searchable()
                ->required()
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
                ->options(self::STATUS_OPTIONS)
                ->helperText('Voreinstellung: nur bezahlte.')
                ->live(),

            Select::make('mode')
                ->label('Inhalt')
                ->options([
                    ParticipantExporter::MODE_ADDRESSES => 'Adressen (eine Zeile je Person)',
                    ParticipantExporter::MODE_PURCHASES => 'Käufe (eine Zeile je Ticket)',
                ])
                ->required()
                // The two shapes have different columns; keeping the old selection
                // would leave keys behind that this shape has no value for.
                ->afterStateUpdated(fn (callable $set, $state) => $set(
                    'columns',
                    array_keys(ParticipantExporter::COLUMNS[$state]
                        ?? ParticipantExporter::COLUMNS[ParticipantExporter::MODE_ADDRESSES]),
                ))
                ->live(),

            Select::make('format')
                ->label('Format')
                ->options([
                    'csv' => 'CSV (mit Kopfzeile)',
                    'txt' => 'Text (Tabulator, mit Kopfzeile)',
                    'xlsx' => 'Excel (XLSX)',
                ])
                ->required(),

            /*
             * SAME CONTROL AS THE TRANSACTION EXPORT: a repeater of simple selects, so
             * the ORDER is chosen by dragging. A plain multi-select cannot express
             * "e-mail first, name second", and for a list that gets pasted somewhere
             * the order is half the point.
             */
            \Filament\Forms\Components\Repeater::make('columns')
                ->label('Spalten (Reihenfolge per Drag & Drop)')
                ->columnSpanFull()
                // Not required: an empty row is dropped, and blocking the form over it
                // would be worse than ignoring it.
                ->simple(Select::make('column')->options(
                    fn (callable $get) => ParticipantExporter::COLUMNS[$get('../../mode')]
                        ?? ParticipantExporter::COLUMNS[ParticipantExporter::MODE_ADDRESSES],
                ))
                ->live()
                ->helperText('Nichts ausgewählt = alle Spalten dieser Form.'),

            Placeholder::make('vorschau')
                ->label('Ergebnis')
                ->columnSpanFull()
                ->content(fn (callable $get) => $this->preview($get)),

            Actions::make([
                FormAction::make('export')
                    ->label('Herunterladen')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action('export'),
            ])->columnSpanFull(),
        ];
    }

    /** The pretix statuses, in the order they mean something. */
    private const STATUS_OPTIONS = [
        'p' => 'bezahlt',
        'n' => 'offen',
        'e' => 'abgelaufen',
        'c' => 'storniert',
    ];

    /** @return array<string, string> */
    private function eventOptions(): array
    {
        return Event::query()
            ->whereNotNull('pretix_event_slug')
            ->orderBy('name')
            ->pluck('name', 'pretix_event_slug')
            ->all();
    }

    /** The addresses of the mail tab's current selection. */
    private function mailAddresses(callable $get): array
    {
        $slug = $get('mail_event_slug');

        if (blank($slug)) {
            return [];
        }

        $gebaut = app(ParticipantExporter::class)->build(
            (string) $slug,
            $get('mail_item_ids') ?? [],
            $get('mail_statuses') ?? [],
            ParticipantExporter::MODE_ADDRESSES,
            ['email'],
        );

        return array_column($gebaut['rows'], 0);
    }

    /**
     * Recomputes the address box after any of its inputs changed.
     *
     * `afterStateHydrated` alone only fills it once, on mount - the box then stayed
     * empty for every selection someone actually made, which is the only moment it
     * matters.
     */
    private function refreshMailList(callable $get, callable $set): void
    {
        $set('mail_liste', $this->mailList($get));
    }

    private function mailList(callable $get): string
    {
        $adressen = $this->mailAddresses($get);

        return implode(match ($get('mail_separator')) {
            'newline' => "\n",
            'comma' => ', ',
            default => '; ',
        }, $adressen);
    }

    private function mailSummary(callable $get): string
    {
        if (blank($get('mail_event_slug'))) {
            return 'Bitte zuerst eine Veranstaltung wählen.';
        }

        $anzahl = count($this->mailAddresses($get));

        if ($anzahl === 0) {
            return 'Keine Adressen für diese Auswahl – prüfe Ticketart und Bestellstatus.';
        }

        return sprintf('%d Adressen, jede genau einmal.', $anzahl);
    }

    /** Downloads the plain address list of the mail tab. */
    public function exportMails(string $format): mixed
    {
        $data = $this->form->getState();
        $get = fn (string $key) => $data[$key] ?? null;

        $gebaut = app(ParticipantExporter::class)->build(
            (string) ($data['mail_event_slug'] ?? ''),
            $data['mail_item_ids'] ?? [],
            $data['mail_statuses'] ?? [],
            ParticipantExporter::MODE_ADDRESSES,
            ['email'],
        );

        if ($gebaut['count'] === 0) {
            return $this->refuse();
        }

        $inhalt = $format === 'txt'
            // One address per line, no header: this file goes into a mail client, and
            // a line saying "E-Mail" would be sent to a mailbox of that name.
            ? implode("\n", array_column($gebaut['rows'], 0)) . "\n"
            : $this->spreadsheet($gebaut, 'csv');

        return $this->deliver($inhalt, sprintf(
            'Adressen-%s-%s.%s',
            \Illuminate\Support\Str::slug((string) ($data['mail_event_slug'] ?? 'event')),
            now()->format('Y-m-d'),
            $format,
        ), $gebaut['count']);
    }

    public function export(): mixed
    {
        $data = $this->form->getState();

        $gebaut = app(ParticipantExporter::class)->build(
            (string) ($data['event_slug'] ?? ''),
            $data['item_ids'] ?? [],
            $data['statuses'] ?? [],
            (string) $data['mode'],
            $data['columns'] ?? [],
        );

        if ($gebaut['count'] === 0) {
            return $this->refuse();
        }

        $format = in_array($data['format'] ?? null, ['csv', 'txt', 'xlsx'], true) ? $data['format'] : 'csv';

        $inhalt = $format === 'txt'
            ? ParticipantExporter::toText($gebaut)
            : $this->spreadsheet($gebaut, $format);

        return $this->deliver($inhalt, sprintf(
            '%s-%s-%s.%s',
            $data['mode'] === ParticipantExporter::MODE_PURCHASES ? 'Kaeufe' : 'Adressen',
            \Illuminate\Support\Str::slug((string) $data['event_slug']),
            now()->format('Y-m-d'),
            $format,
        ), $gebaut['count']);
    }

    /**
     * Refused rather than delivered empty: an empty file looks like a finished export
     * and gets mailed to nobody without anyone noticing.
     */
    private function refuse(): null
    {
        Notification::make()
            ->title('Nichts zu exportieren')
            ->body('Für diese Auswahl gibt es keine Zeilen. Prüfe Ticketart und Bestellstatus.')
            ->warning()
            ->send();

        return null;
    }

    private function spreadsheet(array $gebaut, string $format): string
    {
        $pfad = 'exports/teilnehmer-' . now()->format('Ymd-His') . '-' . uniqid() . '.' . $format;

        Excel::store(
            new ParticipantsExport($gebaut),
            $pfad,
            'local',
            $format === 'xlsx' ? ExcelFormat::XLSX : ExcelFormat::CSV,
        );

        return (string) \Illuminate\Support\Facades\Storage::disk('local')->get($pfad);
    }

    private function deliver(string $inhalt, string $name, int $anzahl): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        Notification::make()
            ->title(sprintf('%d Zeilen exportiert', $anzahl))
            ->success()
            ->send();

        return response()->streamDownload(fn () => print ($inhalt), $name);
    }

    /** A sentence saying what the current selection of the full export would produce. */
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
            $get('mode') === ParticipantExporter::MODE_PURCHASES ? 'Tickets' : 'Personen',
            implode(', ', $gebaut['headings']),
        );
    }
}
