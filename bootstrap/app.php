<?php

use App\Http\Middleware\EnsureWelcomeAccepted;
use App\Http\Middleware\RejectLocationOverrides;
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
        // TRUSTED_PROXIES: "*" (padrão, comportamento anterior) ou uma lista de
        // IPs/CIDRs separados por vírgula. Com "*", o cabeçalho X-Forwarded-For
        // pode ser escolhido por quem acessa o servidor sem passar pelo proxy.
        $trustedProxies = trim((string) env('TRUSTED_PROXIES', '*'));

        $middleware->trustProxies(
            at: $trustedProxies === '*' || $trustedProxies === ''
                ? '*'
                : array_values(array_filter(array_map('trim', explode(',', $trustedProxies)))),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->alias([
            'welcome.accepted' => EnsureWelcomeAccepted::class,
            'location.overrides' => RejectLocationOverrides::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
    