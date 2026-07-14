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
        // Behind the swayy.de reverse proxy (host nginx + Cloudflare) TLS is terminated
        // upstream and the container is reached over plain HTTP. Trust the proxy's
        // X-Forwarded-* headers so Laravel/Filament know the request is really HTTPS and
        // generate https URLs / secure redirects (no mixed content, no redirect loop).
        // The container port is bound to 127.0.0.1 only, so the sole caller is that proxy.
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);

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

        // Persist every server error (5xx) into error_log_entries so 500s are
        // reviewable in the panel / via `errors:recent` without reading raw logs.
        // ErrorLogger is self-contained and never throws.
        $exceptions->report(function (\Throwable $e): void {
            \App\Support\ErrorLogger::record($e);
        });
    })->create();
