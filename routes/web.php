<?php

use App\Http\Controllers\GoCardlessCallbackController;
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

// GoCardless PSD2 consent redirect target.
Route::middleware('auth')
    ->get('/bank/gocardless/callback', GoCardlessCallbackController::class)
    ->name('bank.gocardless.callback');

Route::middleware('auth')->group(function () {
    Route::get('/two-factor-challenge', [TwoFactorChallengeController::class, 'show'])
        ->name('two-factor.challenge');

    Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'verify'])
        ->middleware('throttle:6,1')
        ->name('two-factor.verify');
});
