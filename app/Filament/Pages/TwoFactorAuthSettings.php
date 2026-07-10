<?php

namespace App\Filament\Pages;

use App\Services\Auth\TwoFactorAuthenticationService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Self-service 2FA enrollment (every authenticated user manages their own,
 * regardless of role) - generate secret, scan QR, confirm with a code,
 * show one-time recovery codes, or disable it again.
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

    public ?string $pendingSecret = null;

    public ?string $qrSvg = null;

    public ?array $freshRecoveryCodes = null;

    public function mount(): void
    {
        $this->form->fill();
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

    public function startSetup(TwoFactorAuthenticationService $service): void
    {
        $this->pendingSecret = $service->generateSecretKey();
        $this->qrSvg = $service->qrCodeSvg(auth()->user(), $this->pendingSecret);
        $this->freshRecoveryCodes = null;
    }

    public function confirmSetup(TwoFactorAuthenticationService $service): void
    {
        $state = $this->form->getState();

        if (! $this->pendingSecret || ! $service->verifyCode($this->pendingSecret, $state['code'] ?? '')) {
            Notification::make()->title('Code ungültig, bitte erneut versuchen.')->danger()->send();

            return;
        }

        $codes = $service->generateRecoveryCodes();
        $service->enable(auth()->user(), $this->pendingSecret, $codes);

        $this->pendingSecret = null;
        $this->qrSvg = null;
        $this->freshRecoveryCodes = $codes;
        $this->form->fill();

        // The user just proved possession of the second factor in-session;
        // don't also force the challenge page on their very next request.
        session(['two_factor_passed' => true]);

        Notification::make()->title('Zwei-Faktor-Authentifizierung aktiviert')->success()->send();
    }

    public function disable(TwoFactorAuthenticationService $service): void
    {
        $service->disable(auth()->user());
        $this->freshRecoveryCodes = null;

        Notification::make()->title('Zwei-Faktor-Authentifizierung deaktiviert')->success()->send();
    }
}
