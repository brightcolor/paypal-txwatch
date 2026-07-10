<?php

use App\Http\Controllers\SharedFilterController;
use App\Http\Controllers\TwoFactorChallengeController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/admin'));

Route::middleware('auth')
    ->get('/f/{token}', SharedFilterController::class)
    ->name('filters.shared');

Route::middleware('auth')->group(function () {
    Route::get('/two-factor-challenge', [TwoFactorChallengeController::class, 'show'])
        ->name('two-factor.challenge');

    Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'verify'])
        ->middleware('throttle:6,1')
        ->name('two-factor.verify');
});
