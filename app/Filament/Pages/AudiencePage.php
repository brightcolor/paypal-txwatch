<?php

namespace App\Filament\Pages;

use App\Models\Event;
use App\Models\PretixItem;
use App\Models\PretixPosition;
use App\Services\Audience\AudienceDimensions;
use App\Services\Audience\AudienceOverlap;
use App\Services\Audience\AudienceQuery;
use App\Services\Audience\AudienceStats;
use App\Support\CustomerScope;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * Who comes to which events - and what that says about the programme.
 *
 * THE SELECTION IS THE SCREEN: pick a few events, and everything below answers for
 * exactly those. Every figure is built by the Audience services, so this page holds
 * no arithmetic of its own - and no way past the customer scope that sits in
 * AudienceQuery.
 */
class AudiencePage extends Page implements HasForms
{
    use InteractsWithForms;

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
