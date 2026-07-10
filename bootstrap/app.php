<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // This app has no generic "login" route - authentication lives entirely in the
        // Filament admin panel. Without this, the framework's auth middleware on our own
        // routes (e.g. the shared-filter link /f/{token}, two-factor challenge) tries to
        // redirect unauthenticated visitors to route('login') and 500s with
        // "Route [login] not defined". Point guests at the Filament panel login instead;
        // Laravel stores the intended URL, so after login they return to the link.
        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
