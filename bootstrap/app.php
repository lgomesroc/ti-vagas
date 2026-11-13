<?php

<?php

use App\Http\Middleware\EnsureWelcomeAccepted;
use App\Http\Middleware\EvaluateNetworkReputation;
use App\Http\Middleware\RejectLocationOverrides;
use App\Services\Network\AbuseTracker;
use App\Services\Network\NetworkAction;
use App\Services\Network\NetworkAssessment;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
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

        // Política de acesso por tipo de rede (VPN, proxy, Tor, datacenter).
        // Antes da sessão: acessos bloqueados não criam sessões.
        $middleware->web(prepend: [
            EvaluateNetworkReputation::class,
        ]);

        $middleware->alias([
            'welcome.accepted' => EnsureWelcomeAccepted::class,
            'location.overrides' => RejectLocationOverrides::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Cada resposta 429 de um IP em "evaluate" conta como indício de
        // comportamento de robô. Redes comuns nunca são rastreadas.
        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            $assessment = $request->attributes->get(EvaluateNetworkReputation::ATTRIBUTE);
            $ip = $request->ip();

            if (
                $ip !== null
                && $assessment instanceof NetworkAssessment
                && $assessment->action === NetworkAction::Evaluate
            ) {
                try {
                    app(AbuseTracker::class)->recordStrike($ip);
                } catch (Throwable) {
                    // Falha no cache não pode mudar a resposta ao visitante.
                }
            }

            return null;
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
