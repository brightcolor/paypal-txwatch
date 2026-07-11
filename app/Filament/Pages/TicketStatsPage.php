<?php

namespace App\Filament\Pages;

use App\Models\PretixConnection;
use App\Services\Pretix\PretixTicketStats;
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
        $id = $this->data['connection_id'] ?? null;
        $connection = $id ? PretixConnection::find($id) : null;

        if (! $connection) {
            return collect();
        }

        return app(PretixTicketStats::class)->forConnection($connection);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Aktualisieren')
                ->icon('heroicon-o-arrow-path')
                ->action(function () {
                    $id = $this->data['connection_id'] ?? null;
                    if ($id && $connection = PretixConnection::find($id)) {
                        app(PretixTicketStats::class)->forConnection($connection, fresh: true);
                        Notification::make()->title('Aktualisiert')->success()->send();
                    }
                }),
        ];
    }
}
