<?php

namespace App\Filament\Pages;

use App\Models\FintsConnection;
use App\Services\Bank\FintsBanks;
use App\Services\Bank\FintsClient;
use App\Services\Bank\FintsErrorHelp;
use App\Services\Bank\FintsSync;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * FinTS/HBCI bank auto-fetch setup (replaces GoCardless). Talks directly to the
 * bank (e.g. Sparkasse) via phpFinTS - no third-party aggregator. Admin enters
 * the online-banking credentials + FinTS server details, logs in once (solving a
 * TAN), and a daily job then pulls statements into Bank -> Kontoumsätze and
 * reconciles them. Admin only (banking credentials are highly sensitive).
 */
class FintsConnectionPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationGroup = 'Bank';

    protected static ?string $navigationLabel = 'Auto-Abruf (FinTS)';

    protected static ?string $title = 'Bank-Auto-Abruf (FinTS/HBCI)';

    protected static ?int $navigationSort = 20;

    protected static string $view = 'filament.pages.fints-connection';

    protected static ?string $slug = 'bank-connection';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    /** Character used to mask stored secrets; never occurs in real values. */
    private const MASK = '•';

    /** Fields shown masked (all but the last 4 characters), revealable on demand. */
    private const MASKED_FIELDS = ['product_id', 'username'];

    /**
     * Masks everything but the last 4 characters, so the value stays
     * recognisable ("…C7DB4") without exposing it on screen.
     */
    private static function mask(?string $value): ?string
    {
        if (blank($value)) {
            return $value;
        }

        $visible = mb_substr($value, -4);
        $hidden = max(mb_strlen($value) - 4, 0);

        // Very short values are masked completely - showing 4 of 4 characters
        // would reveal everything.
        return $hidden === 0
            ? str_repeat(self::MASK, mb_strlen($value))
            : str_repeat(self::MASK, $hidden) . $visible;
    }

    /** True while the field still shows the mask (i.e. the user didn't retype it). */
    private static function isMasked(mixed $value): bool
    {
        return is_string($value) && str_contains($value, self::MASK);
    }

    public function mount(): void
    {
        $c = FintsConnection::current();
        $this->form->fill([
            'bank_code' => $c->bank_code,
            'fints_url' => $c->fints_url,
            'product_id' => self::mask($c->product_id),
            'product_version' => $c->product_version ?: '1.0',
            'username' => self::mask($c->username),
            'pin' => null,
            'tan_mode' => $c->tan_mode,
            'tan_medium' => $c->tan_medium,
            'iban' => $c->iban,
            'bank_lookup' => $c->bank_code,
        ]);
    }

    /**
     * Eye button inside a masked field: swaps the mask for the stored value
     * (and back). Only offered while something is actually stored.
     */
    private static function revealAction(string $field): Forms\Components\Actions\Action
    {
        return Forms\Components\Actions\Action::make('reveal_' . $field)
            ->icon(fn ($state) => self::isMasked($state) ? 'heroicon-m-eye' : 'heroicon-m-eye-slash')
            ->tooltip(fn ($state) => self::isMasked($state) ? 'Einblenden' : 'Wieder verdecken')
            ->visible(fn () => filled(FintsConnection::current()->{$field}))
            ->action(function ($state, Forms\Set $set) use ($field) {
                $stored = FintsConnection::current()->{$field};
                $set($field, self::isMasked($state) ? $stored : self::mask($stored));
            });
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('FinTS-Zugangsdaten')
                ->description('Zugangsdaten aus dem Online-Banking der Sparkasse Mecklenburg-Nordwest. PIN und Login werden verschlüsselt gespeichert. Server-Daten (FinTS-URL/BLZ) findest du unter fints.org; die Produkt-/Registrierungsnummer musst du bei der Deutschen Kreditwirtschaft beantragen (fints.org/de/hersteller/produktregistrierung).')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('bank_lookup')
                        ->label('Bank suchen & auswählen')
                        ->helperText(fn () => FintsBanks::count() > 0
                            ? 'Name, Ort oder BLZ tippen (z. B. „Wismar" oder „Sparkasse Meck") – BLZ und FinTS-URL werden automatisch gesetzt. Bei häufigen Namen wie „Sparkasse" am besten den Ort mit eingeben. Nicht dabei? Felder unten manuell ausfüllen.'
                            : 'Bankliste noch nicht eingespielt – BLZ und FinTS-URL bitte manuell eintragen.')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => FintsBanks::search($search))
                        ->getOptionLabelUsing(fn ($value) => FintsBanks::labelFor($value))
                        ->live()
                        ->afterStateUpdated(function ($state, Forms\Set $set) {
                            $bank = $state ? FintsBanks::find($state) : null;
                            if ($bank) {
                                $set('bank_code', $bank->blz);
                                $set('fints_url', $bank->url);
                            }
                        })
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('bank_code')->label('Bankleitzahl (BLZ)')->required()->autocomplete(false)
                        ->helperText('Wird über die Bank-Auswahl oben gesetzt; bei Bedarf manuell überschreibbar.'),
                    Forms\Components\TextInput::make('fints_url')->label('FinTS-URL (PIN/TAN)')->url()->required()->autocomplete(false)
                        ->helperText('Wird über die Bank-Auswahl oben gesetzt.'),
                    Forms\Components\TextInput::make('product_id')->label('Produkt-/Registrierungsnummer (DK)')
                        ->required()->autocomplete(false)
                        ->suffixAction(self::revealAction('product_id'))
                        ->helperText('Aus Datenschutzgründen verdeckt – die letzten 4 Zeichen bleiben sichtbar. Zum Ändern einfach neu eintippen.'),
                    Forms\Components\TextInput::make('product_version')->label('Produktversion')->default('1.0')->required(),
                    Forms\Components\TextInput::make('username')->label('Anmeldename / Legitimations-ID')
                        ->required()->autocomplete(false)
                        ->suffixAction(self::revealAction('username'))
                        ->helperText('Aus Datenschutzgründen verdeckt – die letzten 4 Zeichen bleiben sichtbar. Zum Ändern einfach neu eintippen.'),
                    Forms\Components\TextInput::make('pin')->label('Online-Banking-PIN')->password()->revealable()
                        ->autocomplete('new-password')->placeholder('unverändert lassen zum Beibehalten')
                        // Filament injiziert Closure-Argumente PER NAME: hier muss der
                        // Parameter $state heißen, sonst 500 ("[$s] was unresolvable").
                        ->dehydrated(fn (?string $state) => filled($state)),
                    Forms\Components\TextInput::make('tan_mode')->label('TAN-Verfahren (ID)')
                        ->helperText('Über „TAN-Verfahren anzeigen" (oben) ermitteln.')->numeric(),
                    Forms\Components\TextInput::make('tan_medium')->label('TAN-Medium (Name, optional)'),
                ]),
        ])->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $c = FintsConnection::current();

        // Copy-pasted credentials often carry invisible leading/trailing spaces,
        // which the bank rejects with "Anmeldename oder PIN ist falsch" (9931).
        foreach (['bank_code', 'fints_url', 'product_id', 'product_version', 'username', 'tan_mode', 'tan_medium'] as $key) {
            $value = $state[$key] ?? null;
            $value = is_string($value) ? trim($value) : $value;

            // A still-masked field means "unchanged" - never write the mask
            // itself into the database (that would destroy the stored value).
            if (in_array($key, self::MASKED_FIELDS, true) && self::isMasked($value)) {
                continue;
            }

            $c->{$key} = $value;
        }
        if (filled($state['pin'] ?? null)) {
            $c->pin = trim($state['pin']);
        }
        $c->save();

        Notification::make()->title('Gespeichert')->success()->send();
    }

    public function getConnectionProperty(): FintsConnection
    {
        return FintsConnection::current();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('tanModes')
                ->label('TAN-Verfahren anzeigen')
                ->icon('heroicon-o-list-bullet')->color('gray')
                ->visible(fn () => FintsConnection::current()->hasCredentials())
                ->action(function () {
                    try {
                        $modes = (new FintsClient(FintsConnection::current()))->listTanModes();
                        if (empty($modes)) {
                            Notification::make()->title('Keine TAN-Verfahren gemeldet')->warning()->send();

                            return;
                        }
                        $lines = collect($modes)->map(function ($m) {
                            $media = $m['needsMedium'] && $m['media'] ? ' – Medien: ' . implode(', ', $m['media']) : '';

                            return "ID {$m['id']}: {$m['name']}{$media}";
                        })->implode("\n");

                        Notification::make()->title('Verfügbare TAN-Verfahren')->body($lines)->persistent()->info()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Abfrage fehlgeschlagen')->body(FintsErrorHelp::decorate($e->getMessage()))->danger()->persistent()->send();
                    }
                }),

            Action::make('connect')
                ->label('Login / Bank verbinden')
                ->icon('heroicon-o-link')
                ->visible(fn () => FintsConnection::current()->hasCredentials() && filled(FintsConnection::current()->tan_mode))
                ->requiresConfirmation()
                ->modalDescription('TxWatch meldet sich mit deinen Zugangsdaten bei der Bank an. Verlangt die Bank eine TAN, wirst du anschließend zur TAN-Eingabe aufgefordert.')
                ->action(fn () => $this->beginLogin()),

            Action::make('submitTan')
                ->label('TAN eingeben')
                ->icon('heroicon-o-key')->color('warning')
                ->visible(fn () => FintsConnection::current()->status === FintsConnection::STATUS_NEEDS_TAN)
                ->modalDescription(fn () => FintsConnection::current()->tan_challenge ?: 'Bitte die TAN aus deiner App/deinem TAN-Generator eingeben.')
                ->form([
                    Forms\Components\TextInput::make('tan')->label('TAN')->required()->autocomplete(false),
                ])
                ->action(fn (array $data) => $this->submitTan($data['tan'])),

            Action::make('sync')
                ->label('Jetzt abrufen')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn () => FintsConnection::current()->isActive())
                ->action(function () {
                    $result = app(FintsSync::class)->syncSafely(FintsConnection::current());
                    $this->reportSync($result);
                }),

            Action::make('disconnect')
                ->label('Trennen')
                ->icon('heroicon-o-x-mark')->color('gray')
                ->visible(fn () => FintsConnection::current()->status !== FintsConnection::STATUS_NEW)
                ->requiresConfirmation()
                ->action(function () {
                    FintsConnection::current()->update([
                        'persisted_state' => null, 'pending_state' => null, 'pending_action' => null,
                        'tan_challenge' => null, 'tan_image' => null,
                        'status' => FintsConnection::STATUS_NEW, 'last_error' => null,
                    ]);
                    Notification::make()->title('Verbindung getrennt')->success()->send();
                }),
        ];
    }

    private function beginLogin(): void
    {
        $c = FintsConnection::current();
        try {
            $res = (new FintsClient($c))->beginLogin();

            if ($res['status'] === FintsConnection::STATUS_NEEDS_TAN) {
                $c->update([
                    'status' => FintsConnection::STATUS_NEEDS_TAN,
                    'pending_state' => $res['state'],
                    'pending_action' => $res['action'],
                    'tan_challenge' => $res['challenge'] ?? null,
                    'tan_image' => $res['image'] ?? null,
                    'last_error' => null,
                ]);
                Notification::make()->title('TAN erforderlich')
                    ->body('Die Bank verlangt eine TAN. Bitte oben „TAN eingeben".')->warning()->send();

                return;
            }

            // No TAN required - straight to active.
            $c->update([
                'status' => FintsConnection::STATUS_ACTIVE,
                'persisted_state' => $res['state'],
                'pending_state' => null, 'pending_action' => null, 'tan_challenge' => null, 'tan_image' => null,
                'last_error' => null,
            ]);
            $this->firstSync();
        } catch (\Throwable $e) {
            $c->update(['status' => FintsConnection::STATUS_ERROR, 'last_error' => $e->getMessage()]);
            Notification::make()->title('Login fehlgeschlagen')->body(FintsErrorHelp::decorate($e->getMessage()))->danger()->persistent()->send();
        }
    }

    private function submitTan(string $tan): void
    {
        $c = FintsConnection::current();
        try {
            $res = (new FintsClient($c))->submitLoginTan($c->pending_state, $c->pending_action, $tan);

            $c->update([
                'status' => FintsConnection::STATUS_ACTIVE,
                'persisted_state' => $res['state'],
                'pending_state' => null, 'pending_action' => null, 'tan_challenge' => null, 'tan_image' => null,
                'last_error' => null,
            ]);
            Notification::make()->title('Angemeldet')->success()->send();
            $this->firstSync();
        } catch (\Throwable $e) {
            $c->update(['status' => FintsConnection::STATUS_ERROR, 'last_error' => $e->getMessage()]);
            Notification::make()->title('TAN-Bestätigung fehlgeschlagen')->body(FintsErrorHelp::decorate($e->getMessage()))->danger()->persistent()->send();
        }
    }

    private function firstSync(): void
    {
        $result = app(FintsSync::class)->syncSafely(FintsConnection::current());
        $this->reportSync($result);
    }

    /** @param array<string, mixed> $result */
    private function reportSync(array $result): void
    {
        if (isset($result['error'])) {
            Notification::make()->title('Abruf fehlgeschlagen')->body(FintsErrorHelp::decorate($result['error']))->danger()->persistent()->send();

            return;
        }

        Notification::make()->title('Abruf fertig')
            ->body("{$result['imported']} neu importiert, {$result['matched']} zugeordnet.")->success()->send();
    }
}
