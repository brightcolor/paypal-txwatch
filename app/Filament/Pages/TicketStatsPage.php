<?php

namespace App\Filament\Pages;

use App\Models\PretixConnection;
use App\Services\Pretix\PretixTicketStats;
use App\Services\Pretix\PretixUnavailable;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * Live ticket capacity vs. sold per pretix event, pulled from the quota
 * availability endpoint (cached). Operator-facing (managing pretix), so gated
 * on manage-pretix-connections.
 */
class TicketStatsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationGroup = 'pretix';

    protected static ?string $navigationLabel = 'Ticket-Statistik';

    protected static ?string $title = 'Ticket-Statistik (pretix)';

    protected static string $view = 'filament.pages.ticket-stats';

    public ?array $data = [];

    /**
     * Rows or failure of this request, shared by the view and the refresh
     * action so pretix is asked once and a failure is reported once.
     *
     * @var array{rows: Collection<int, array<string, mixed>>, failure: ?PretixUnavailable}|null
     */
    private ?array $loaded = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('manage-pretix-connections') ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return PretixConnection::query()->where('is_active', true)->exists()
            && (auth()->user()?->can('manage-pretix-connections') ?? false);
    }

    public function mount(): void
    {
        $this->form->fill([
            'connection_id' => PretixConnection::query()->where('is_active', true)->value('id'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('connection_id')
                    ->label('pretix-Verbindung')
                    ->options(PretixConnection::query()->where('is_active', true)->pluck('name', 'id'))
                    ->live()
                    ->native(false),
            ])
            ->statePath('data');
    }

    /** @return Collection<int, array<string, mixed>> */
    public function getRowsProperty(): Collection
    {
        return $this->load()['rows'];
    }

    /** Why the figures are missing, or null when they loaded. */
    public function getFailureProperty(): ?PretixUnavailable
    {
        return $this->load()['failure'];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Aktualisieren')
                ->icon('heroicon-o-arrow-path')
                ->action(function () {
                    if (! $this->connection()) {
                        return;
                    }

                    $failure = $this->load(fresh: true)['failure'];

                    if ($failure) {
                        Notification::make()
                            ->title('Aktualisieren fehlgeschlagen')
                            ->body($failure->userMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()->title('Aktualisiert')->success()->send();
                }),
        ];
    }

    /** @return array{rows: Collection<int, array<string, mixed>>, failure: ?PretixUnavailable} */
    private function load(bool $fresh = false): array
    {
        if ($this->loaded !== null && ! $fresh) {
            return $this->loaded;
        }

        $connection = $this->connection();

        if (! $connection) {
            return $this->loaded = ['rows' => collect(), 'failure' => null];
        }

        try {
            return $this->loaded = [
                'rows' => app(PretixTicketStats::class)->forConnection($connection, $fresh),
                'failure' => null,
            ];
        } catch (PretixUnavailable $e) {
            // Into laravel.log and error_log_entries, like any other server-side error.
            report($e);

            return $this->loaded = ['rows' => collect(), 'failure' => $e];
        }
    }

    private function connection(): ?PretixConnection
    {
        $id = $this->data['connection_id'] ?? null;

        return $id ? PretixConnection::find($id) : null;
    }
}
