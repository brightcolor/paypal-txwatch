<?php

namespace App\Filament\Pages;

use App\Models\BankConnection;
use App\Services\Bank\GoCardlessClient;
use App\Services\Bank\GoCardlessSync;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * GoCardless (PSD2) bank auto-fetch setup. Admin enters the API credentials
 * from the GoCardless portal, picks the bank, and authorises read access; a
 * daily job then pulls transactions into Bank -> Kontoumsätze and reconciles
 * them. Admin only (bank credentials are sensitive).
 */
class BankConnectionPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationGroup = 'Bank';

    protected static ?string $navigationLabel = 'Auto-Abruf (GoCardless)';

    protected static ?string $title = 'Bank-Auto-Abruf (GoCardless)';

    protected static ?int $navigationSort = 20;

    protected static string $view = 'filament.pages.bank-connection';

    protected static ?string $slug = 'bank-connection';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public function mount(): void
    {
        $c = BankConnection::current();
        $this->form->fill([
            'secret_id' => $c->secret_id,
            'secret_key' => null,
            'institution_id' => $c->institution_id,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('GoCardless-Zugangsdaten')
                ->description('Aus dem GoCardless-Bank-Account-Data-Portal (Registrierung mit banking@hsp-tickets.de). Der Secret Key wird verschlüsselt gespeichert.')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('secret_id')->label('Secret ID')->autocomplete(false),
                    Forms\Components\TextInput::make('secret_key')->label('Secret Key')->password()->revealable()
                        ->autocomplete('new-password')->placeholder('unverändert lassen zum Beibehalten')
                        ->dehydrated(fn (?string $s) => filled($s)),
                    Forms\Components\Select::make('institution_id')->label('Bank')
                        ->options(fn () => $this->institutionOptions())
                        ->searchable()
                        ->helperText('Erscheint, sobald gültige Zugangsdaten gespeichert sind. Für dich: Sparkasse Mecklenburg-Nordwest.')
                        ->columnSpanFull(),
                ]),
        ])->statePath('data');
    }

    /** @return array<string, string> */
    private function institutionOptions(): array
    {
        $c = BankConnection::current();
        if (! $c->hasCredentials()) {
            return [];
        }

        try {
            $list = Cache::remember('gocardless_institutions_de', now()->addDay(),
                fn () => (new GoCardlessClient($c))->institutions('DE'));

            return collect($list)->mapWithKeys(fn ($i) => [$i['id'] => $i['name'] . ($i['bic'] ? " ({$i['bic']})" : '')])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $c = BankConnection::current();

        $before = $c->secret_id;
        $c->fill(array_filter($state, fn ($v, $k) => $k !== 'secret_key' || filled($v), ARRAY_FILTER_USE_BOTH));
        if (filled($state['secret_key'] ?? null)) {
            $c->secret_key = $state['secret_key'];
        }
        $c->institution_id = $state['institution_id'] ?? $c->institution_id;
        $c->save();

        // Credentials changed -> invalidate the cached token and bank list.
        GoCardlessClient::forgetToken();
        Cache::forget('gocardless_institutions_de');

        Notification::make()->title('Gespeichert')->success()->send();
    }

    public function getConnectionProperty(): BankConnection
    {
        return BankConnection::current();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('connect')
                ->label('Bank verbinden / neu freigeben')
                ->icon('heroicon-o-link')
                ->visible(fn () => BankConnection::current()->hasCredentials() && filled(BankConnection::current()->institution_id))
                ->requiresConfirmation()
                ->modalDescription('Du wirst zur Login-Seite deiner Bank weitergeleitet, um TxWatch lesenden Zugriff zu erlauben.')
                ->action(function () {
                    $c = BankConnection::current();
                    try {
                        $name = $this->institutionOptions()[$c->institution_id] ?? $c->institution_id;
                        $ref = 'txw-' . Str::random(16);
                        $redirect = rtrim(config('app.url'), '/') . '/bank/gocardless/callback';

                        $req = (new GoCardlessClient($c))->createRequisition($c->institution_id, $redirect, $ref);

                        $c->update([
                            'institution_name' => $name,
                            'requisition_id' => $req['id'],
                            'requisition_ref' => $ref,
                            'status' => BankConnection::STATUS_LINKING,
                            'last_error' => null,
                        ]);

                        return redirect()->away($req['link']);
                    } catch (\Throwable $e) {
                        Notification::make()->title('Verbinden fehlgeschlagen')->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),

            Action::make('sync')
                ->label('Jetzt abrufen')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn () => BankConnection::current()->isConnected())
                ->action(function () {
                    $result = app(GoCardlessSync::class)->syncSafely(BankConnection::current());
                    if (isset($result['error'])) {
                        Notification::make()->title('Abruf fehlgeschlagen')->body($result['error'])->danger()->send();

                        return;
                    }
                    Notification::make()->title('Abruf fertig')
                        ->body("{$result['imported']} neu importiert, {$result['matched']} zugeordnet.")->success()->send();
                }),

            Action::make('disconnect')
                ->label('Trennen')
                ->icon('heroicon-o-x-mark')->color('gray')
                ->visible(fn () => BankConnection::current()->status !== BankConnection::STATUS_NEW)
                ->requiresConfirmation()
                ->action(function () {
                    BankConnection::current()->update([
                        'requisition_id' => null, 'requisition_ref' => null, 'agreement_id' => null,
                        'account_ids' => null, 'status' => BankConnection::STATUS_NEW,
                        'consent_expires_at' => null,
                    ]);
                    Notification::make()->title('Verbindung getrennt')->success()->send();
                }),
        ];
    }
}
