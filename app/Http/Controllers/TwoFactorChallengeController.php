<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Auth\PinCheck;
use App\Services\Auth\TrustedDevice;
use App\Services\Auth\TwoFactorAuthenticationService;
use App\Services\Auth\TwoFactorPin;
use App\Support\TwoFactorSettings;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * The second step for accounts with 2FA: the code from the authenticator app
 * (or a recovery code), or the unlock PIN on a confirmed device.
 *
 * A return through "Angemeldet bleiben" after the session ended (session flag
 * two_factor_reentry, set here or in EnsureTwoFactorChallengeIsPassed) never
 * stops half-way: with a usable PIN the page asks for it, otherwise the user
 * is signed out completely and lands on the sign-in page.
 */
class TwoFactorChallengeController extends Controller
{
    public function __construct(
        private readonly TwoFactorPin $pin,
        private readonly TrustedDevice $devices,
    ) {
    }

    public function show(Request $request): View|RedirectResponse
    {
        $user = Auth::user();

        if (! $user || ! $user->hasTwoFactorEnabled() || session('two_factor_passed')) {
            return redirect('/admin');
        }

        if (Auth::viaRemember()) {
            session(['two_factor_reentry' => true]);
        }

        $pinUsable = $this->pinUsable($user, $request);

        if (session('two_factor_reentry') && ! $pinUsable) {
            return $this->signOut($request, 'Sitzung abgelaufen', 'Melde dich bitte neu an.');
        }

        return view('auth.two-factor-challenge', [
            'mode' => $pinUsable && $request->query('via') !== 'code' ? 'pin' : 'code',
            'pinUsable' => $pinUsable,
        ]);
    }

    public function verify(Request $request, TwoFactorAuthenticationService $service): RedirectResponse
    {
        $user = Auth::user();

        if ($request->has('pin')) {
            return $this->verifyPin($request, $user);
        }

        $data = $request->validate(
            ['code' => ['required', 'string']],
            ['code.required' => 'Bitte gib den Code aus deiner Authenticator-App oder einen Wiederherstellungscode ein.'],
        );

        $valid = $service->verifyCode($user->two_factor_secret, $data['code'])
            || $service->verifyAndConsumeRecoveryCode($user, $data['code']);

        if (! $valid) {
            return back()->withErrors([
                'code' => 'Der Code ist ungültig oder abgelaufen. Nimm den aktuellen Code aus der App oder einen Wiederherstellungscode.',
            ]);
        }

        // The app code confirms this browser: from now on it accepts the PIN,
        // and a PIN locked by wrong entries works again.
        $this->devices->trust($user);
        $this->pin->resetFailures($user);

        return $this->pass($request);
    }

    private function verifyPin(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(
            ['pin' => ['required', 'string']],
            ['pin.required' => 'Bitte gib deine PIN ein.'],
        );

        if (! $this->pinUsable($user, $request)) {
            if (session('two_factor_reentry')) {
                return $this->signOut($request, 'PIN hier nicht verfügbar',
                    'Melde dich bitte neu an und bestätige mit dem Code aus deiner Authenticator-App.');
            }

            return redirect()->route('two-factor.challenge', ['via' => 'code'])->withErrors([
                'code' => 'Die PIN gilt auf diesem Gerät nicht. Bestätige bitte mit dem Code aus deiner Authenticator-App.',
            ]);
        }

        return match ($this->pin->attempt($user, $data['pin'])) {
            PinCheck::Accepted => $this->pass($request),
            PinCheck::Rejected => back()->withErrors(['pin' => $this->wrongPinMessage($user)]),
            PinCheck::Locked => $this->lockOut($request, $user),
        };
    }

    private function wrongPinMessage(User $user): string
    {
        $left = $this->pin->remainingAttempts($user);

        return $left === 1
            ? 'Die PIN stimmt nicht. Du hast noch einen Versuch, danach brauchst du den Code aus deiner Authenticator-App.'
            : "Die PIN stimmt nicht. Du hast noch {$left} Versuche.";
    }

    private function lockOut(Request $request, User $user): RedirectResponse
    {
        // Every confirmed device needs the app code again, this one included.
        $this->devices->forgetAll($user);

        Log::warning('Entsperr-PIN nach zu vielen Fehlversuchen gesperrt', ['user_id' => $user->getKey()]);

        return $this->signOut($request, 'PIN gesperrt', sprintf(
            'Die PIN wurde %d-mal falsch eingegeben. Melde dich neu an und bestätige mit dem Code aus deiner '
            . 'Authenticator-App, danach gilt die PIN wieder.',
            TwoFactorSettings::pinMaxAttempts(),
        ), persistent: true);
    }

    private function pinUsable(User $user, Request $request): bool
    {
        return $user->hasTwoFactorPin()
            && ! $this->pin->isLocked($user)
            && $this->devices->isTrusted($user, $request);
    }

    private function pass(Request $request): RedirectResponse
    {
        $request->session()->regenerate();
        $request->session()->forget('two_factor_reentry');
        $request->session()->put('two_factor_passed', true);

        return redirect($request->session()->pull('two_factor_redirect', '/admin'));
    }

    /**
     * Signs out on this device only: its "Angemeldet bleiben" cookie goes,
     * other devices keep theirs. The sign-in page shows the reason.
     */
    private function signOut(Request $request, string $title, string $body, bool $persistent = false): RedirectResponse
    {
        Auth::guard('web')->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $notification = Notification::make()->title($title)->body($body)->warning();
        if ($persistent) {
            $notification->persistent();
        }
        $notification->send();

        return redirect()->to(Filament::getDefaultPanel()->getLoginUrl());
    }
}
