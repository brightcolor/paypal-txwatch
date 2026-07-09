<?php

use App\Http\Controllers\SharedFilterController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')
    ->get('/f/{token}', SharedFilterController::class)
    ->name('filters.shared');
