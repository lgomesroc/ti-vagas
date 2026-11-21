<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Garante que o site seja servido somente por https.
 *
 * - Redireciona (301) pedidos http para a mesma URL em https.
 * - Envia HSTS, para o navegador não tentar http nas próximas visitas.
 * - Envia `upgrade-insecure-requests`, que faz o navegador trocar http por
 *   https em qualquer recurso da página (evita "conteúdo misto", que faz o
 *   navegador marcar o site como não seguro).
 *
 * A requisição é considerada https pelo cabeçalho X-Forwarded-Proto do proxy
 * da hospedagem (ver trustProxies em bootstrap/app.php). O endpoint de saúde
 * é exceção: o monitor da hospedagem o chama direto, sem TLS.
 */
class ForceHttps
{
    public const HSTS_MAX_AGE = 31536000;

    public function handle(Request $request, Closure $next): Response
    {
        if (!config('app.force_https')) {
            return $next($request);
        }

        if (!$request->isSecure()) {
            if ($request->is('up')) {
                return $next($request);
            }

            // Só GET/HEAD são redirecionados com segurança; um POST por http
            // perderia o corpo. Para os demais, recusa.
            if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
                return response('HTTPS obrigatório.', 400);
            }

            return redirect()->secure($request->getRequestUri(), 301);
        }

        $response = $next($request);

        // Sem includeSubDomains/preload de propósito: o domínio pai pode ter
        // outros serviços que ainda não usam https.
        $response->headers->set(
            'Strict-Transport-Security',
            'max-age=' . self::HSTS_MAX_AGE
        );

        if (!$response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', 'upgrade-insecure-requests');
        }

        return $response;
    }
}
