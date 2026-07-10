<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates every panel request behind a TOTP/recovery-code challenge once a
 * user has 2FA enabled, until they've passed it for the current session
 * (see TwoFactorChallengeController). Attached as Filament panel
 * authMiddleware, so it runs after the normal login check.
 */
class EnsureTwoFactorChallengeIsPassed
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->hasTwoFactorEnabled() && ! session('two_factor_passed')) {
            session(['two_factor_redirect' => $request->fullUrl()]);

            return redirect()->route('two-factor.challenge');
        }

        return $next($request);
    }
}
