<?php

namespace App\Http\Middleware;

use App\Filament\Pages\TwoFactorAuthSettings;
use Closure;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two things, in order:
 *  1. Once a user HAS 2FA enabled, gate every panel request behind the
 *     TOTP/recovery-code challenge until it's passed for the session.
 *  2. If an admin has NOT enabled 2FA yet, REMIND them (once per session) - but
 *     never block: they can keep working and enrol whenever they get to it.
 *     Nagging on (config auth.two_factor_nag_admins), enforcement off.
 * Attached as Filament panel authMiddleware, so it runs after the login check.
 */
class EnsureTwoFactorChallengeIsPassed
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if ($user->hasTwoFactorEnabled() && ! session('two_factor_passed')) {
            session(['two_factor_redirect' => $request->fullUrl()]);

            return redirect()->route('two-factor.challenge');
        }

        // Remind - but never block - admins who haven't set up 2FA yet. A single
        // persistent warning per session is enough nagging; forcing enrolment
        // before any work can happen is too much.
        if (config('auth.two_factor_nag_admins', true)
            && $user->hasRole('admin')
            && ! $user->hasTwoFactorEnabled()
            && ! session('two_factor_nag_shown')
        ) {
            session(['two_factor_nag_shown' => true]);

            Notification::make()
                ->title('2FA empfohlen')
                ->body('Dein Admin-Konto ist noch ohne Zwei-Faktor-Authentifizierung. Bitte richte sie bald ein – du kannst aber ganz normal weiterarbeiten.')
                ->warning()
                ->persistent()
                ->actions([
                    \Filament\Notifications\Actions\Action::make('setup')
                        ->label('Jetzt einrichten')
                        ->url(TwoFactorAuthSettings::getUrl()),
                ])
                ->send();
        }

        return $next($request);
    }
}
