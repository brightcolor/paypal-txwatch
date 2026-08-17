<?php

use App\Http\Controllers\EnableBankingCallbackController;
use App\Http\Controllers\PretixWebhookController;
use App\Http\Controllers\SharedFilterController;
use App\Http\Controllers\TwoFactorChallengeController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/admin'));

// Public pretix webhook (authorized by the per-connection secret in the URL);
// throttled so a misbehaving sender can't flood the queue.
Route::post('/webhooks/pretix/{secret}', PretixWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('webhooks.pretix');

Route::middleware('auth')
    ->get('/f/{token}', SharedFilterController::class)
    ->name('filters.shared');

/*
 * The bank's return address for Enable Banking.
 *
 * THE PATH IS PART OF THE CONTRACT: it is registered in the Enable Banking
 * control panel and has to match character for character. The route name is set
 * explicitly rather than derived, because the setup page builds the address from
 * it - an auto-generated name would change silently when the controller is
 * renamed, and the registered entry would stop matching.
 *
 * Behind `auth` on purpose; see the controller for why that works with a
 * SameSite=lax session cookie.
 */
Route::middleware('auth')
    ->get('/bank/enablebanking/callback', EnableBankingCallbackController::class)
    ->name('enablebanking.callback');

Route::middleware('auth')->group(function () {
    Route::get('/two-factor-challenge', [TwoFactorChallengeController::class, 'show'])
        ->name('two-factor.challenge');

    Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'verify'])
        ->middleware('throttle:6,1')
        ->name('two-factor.verify');
});
