<?php

namespace App\Http\Controllers;

use App\Services\Auth\TwoFactorAuthenticationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class TwoFactorChallengeController extends Controller
{
    public function show(): View|RedirectResponse
    {
        $user = Auth::user();

        if (! $user || ! $user->hasTwoFactorEnabled() || session('two_factor_passed')) {
            return redirect('/admin');
        }

        return view('auth.two-factor-challenge');
    }

    public function verify(Request $request, TwoFactorAuthenticationService $service): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = Auth::user();

        $valid = $service->verifyCode($user->two_factor_secret, $data['code'])
            || $service->verifyAndConsumeRecoveryCode($user, $data['code']);

        if (! $valid) {
            return back()->withErrors(['code' => 'Der Code ist ungültig oder abgelaufen.']);
        }

        session(['two_factor_passed' => true]);
        $redirect = session()->pull('two_factor_redirect', '/admin');

        return redirect($redirect);
    }
}
