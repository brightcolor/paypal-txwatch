<?php

namespace App\Filament\Pages;

use App\Services\Auth\TrustedDevice;
use App\Services\Auth\TwoFactorAuthenticationService;
use App\Services\Auth\TwoFactorPin;
use App\Support\TwoFactorSettings;
use Closure;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Self-service 2FA enrollment (every authenticated user manages their own,
 * regardless of role) - generate secret, scan QR, confirm with a code,
 * show one-time recovery codes, or disable it again. With 2FA on, the user
 * may set an unlock PIN for confirmed devices and forget the other devices.
 *
 * @property Form $form
 * @property Form $pinForm
 */
class TwoFactorAuthSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Einstellungen';

    protected static ?string $navigationLabel = 'Zwei-Faktor-Authentifizierung';

    protected static ?string $title = 'Zwei-Faktor-Authentifizierung';

    protected static string $view = 'filament.pages.two-factor-auth-settings';

    public ?array $data = [];

    public ?array $pinData = [];

    public ?string $pendingSecret = null;

    public ?string $qrSvg = null;

    public ?array $freshRecoveryCodes = null;

    public function mount(): void
    {
        $this->form->fill();
        $this->pinForm->fill();
    }

    protected function getForms(): array
    {
        return ['form', 'pinForm'];
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('code')
                ->label('Code aus der Authenticator-App')
                ->numeric()
                ->required(),
        ])->statePath('data');
    }

    public function pinForm(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('pin')
                ->label('Neue PIN')
                ->password()
                ->revealable()
                ->required()
                ->inputMode('numeric')
                ->autocomplete('new-password')
                ->rules([
                    'digits_between:' . TwoFactorSettings::pinMinLength() . ',' . TwoFactorSettings::PIN_MAX_LENGTH,
                    fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (is_string($value) && TwoFactorPin::isGuessable($value)) {
                            $fail('Diese PIN ist zu leicht zu erraten. Nimm keine gleichen Ziffern und keine Folge wie 123456.');
                        }
                    },
                ])
                ->validationMessages([
                    'required' => 'Bitte gib eine PIN ein.',
                    'digits_between' => 'Die PIN besteht aus :min bis :max Ziffern, ohne Buchstaben oder Leerzeichen.',
                ]),
            Forms\Components\TextInput::make('pin_confirmation')
                ->label('PIN wiederholen')
                ->password()
                ->revealable()
                ->required()
                ->inputMode('numeric')
                ->autocomplete('new-password')
                ->same('pin')
                ->validationMessages([
                    'required' => 'Bitte gib die PIN ein zweites Mal ein.',
                    'same' => 'Die beiden Eingaben stimmen nicht überein. Gib die PIN zweimal gleich ein.',
                ]),
        ])->statePath('pinData')->columns(2);
    }

    protected function getViewData(): array
    {
        return [
            'pinMinLength' => TwoFactorSettings::pinMinLength(),
            'pinMaxLength' => TwoFactorSettings::PIN_MAX_LENGTH,
            'pinMaxAttempts' => TwoFactorSettings::pinMaxAttempts(),
            'trustedDeviceDays' => TwoFactorSettings::trustedDeviceDays(),
        ];
    }

    public function startSetup(TwoFactorAuthenticationService $service): void
    {
        $this->pendingSecret = $service->generateSecretKey();
        $this->qrSvg = $service->qrCodeSvg(auth()->user(), $this->pendingSecret);
        $this->freshRecoveryCodes = null;
    }

    public function confirmSetup(TwoFactorAuthenticationService $service, TrustedDevice $devices): void
    {
        $state = $this->form->getState();

        if (! $this->pendingSecret || ! $service->verifyCode($this->pendingSecret, $state['code'] ?? '')) {
            Notification::make()
                ->title('Code ungültig')
                ->body('Nimm den aktuellen Code aus der App und versuche es erneut.')
                ->danger()
                ->send();

            return;
        }

        $codes = $service->generateRecoveryCodes();
        $service->enable(auth()->user(), $this->pendingSecret, $codes);

        $this->pendingSecret = null;
        $this->qrSvg = null;
        $this->freshRecoveryCodes = $codes;
        $this->form->fill();

        // The user just proved possession of the second factor in-session;
        // don't also force the challenge page on their very next request, and
        // count this browser as a confirmed device.
        session(['two_factor_passed' => true]);
        $devices->trust(auth()->user());

        Notification::make()->title('Zwei-Faktor-Authentifizierung aktiviert')->success()->send();
    }

    public function disable(TwoFactorAuthenticationService $service): void
    {
        $service->disable(auth()->user());
        $this->freshRecoveryCodes = null;

        Notification::make()
            ->title('Zwei-Faktor-Authentifizierung deaktiviert')
            ->body('Die PIN ist entfernt, und kein Gerät gilt mehr als bestätigt.')
            ->success()
            ->send();
    }

    public function savePin(TwoFactorPin $pin, TrustedDevice $devices): void
    {
        $user = auth()->user();

        if (! $user->hasTwoFactorEnabled()) {
            Notification::make()
                ->title('Erst die Zwei-Faktor-Authentifizierung einrichten')
                ->body('Eine PIN gibt es nur zusammen mit der Authenticator-App. Richte sie oben ein, dann kannst du eine PIN festlegen.')
                ->warning()
                ->send();

            return;
        }

        $state = $this->pinForm->getState();
        $pin->set($user, $state['pin']);

        // This session passed the second factor, so this browser counts as
        // confirmed and accepts the PIN from now on.
        $devices->trust($user);
        $this->pinForm->fill();

        Notification::make()
            ->title('PIN gespeichert')
            ->body('Auf diesem Gerät und auf jedem, auf dem du dich mit dem Code aus der App bestätigst, reicht künftig die PIN.')
            ->success()
            ->send();
    }

    public function removePin(TwoFactorPin $pin): void
    {
        $pin->remove(auth()->user());
        $this->pinForm->fill();

        Notification::make()
            ->title('PIN entfernt')
            ->body('Läuft deine Sitzung ab, wirst du ab jetzt vollständig abgemeldet und meldest dich neu an.')
            ->success()
            ->send();
    }

    public function forgetOtherDevices(TrustedDevice $devices): void
    {
        $devices->forgetOthers(auth()->user());

        Notification::make()
            ->title('Andere Geräte vergessen')
            ->body('Auf allen anderen Geräten verlangt TxWatch beim nächsten Mal wieder den Code aus der App. Dieses Gerät bleibt bestätigt.')
            ->success()
            ->send();
    }
}
