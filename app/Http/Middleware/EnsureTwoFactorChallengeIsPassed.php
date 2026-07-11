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
 *  2. If 2FA is required for admins (config auth.two_factor_required_for_admins)
 *     and an admin has NOT enabled it yet, force them onto the 2FA settings
 *     page until they do - so an admin account can't stay unprotected.
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

        // Enforce enrollment for admins. Allow the settings page itself and
        // logout so they can actually set it up / sign out.
        if (config('auth.two_factor_required_for_admins', true)
            && $user->hasRole('admin')
            && ! $user->hasTwoFactorEnabled()
            && ! $request->routeIs('filament.admin.auth.logout')
            && ! $request->is(ltrim(TwoFactorAuthSettings::getUrl(isAbsolute: false), '/') . '*')
        ) {
            Notification::make()
                ->title('Zwei-Faktor-Authentifizierung erforderlich')
                ->body('Als Administrator musst du 2FA aktivieren, bevor du fortfahren kannst.')
                ->warning()
                ->persistent()
                ->send();

            return redirect(TwoFactorAuthSettings::getUrl());
        }

        return $next($request);
    }
}
