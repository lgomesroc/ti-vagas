<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureWelcomeAccepted
{
    public const TERMS_VERSION = '1.0';

    public const TERMS_COOKIE = 'ti_vagas_terms_accepted';

    public const TERMS_SESSION_KEY = 'terms_accepted_version';

    public const COOKIE_NOTICE_COOKIE = 'ti_vagas_cookie_notice';

    public const COOKIE_NOTICE_SESSION_KEY = 'cookie_notice_acknowledged';

    public function handle(Request $request, Closure $next): Response
    {
        $accepted = self::isAccepted($request);

        // Diagnóstico temporário: registrar somente o acesso à página inicial.
        if ($request->is('/')) {
            Log::warning('TI_VAGAS_WELCOME_DIAGNOSTIC', [
                'accepted' => $accepted,
                'terms_cookie_received' => $request->cookie(self::TERMS_COOKIE) === self::TERMS_VERSION,
                'terms_session_received' => $request->hasSession()
                    && $request->session()->get(self::TERMS_SESSION_KEY) === self::TERMS_VERSION,
                'session_driver' => config('session.driver'),
                'secure_request' => $request->isSecure(),
                'session_available' => $request->hasSession(),
            ] + self::requestDiagnostics($request));
        }

        if (!$accepted) {
            // Esta resposta depende do cookie/sessão de cada visitante: nenhum
            // cache (navegador, CDN ou proxy) pode reaproveitá-la para outro.
            return self::withoutCaching(redirect()->route('welcome'));
        }

        return $next($request);
    }

    /**
     * Impede que respostas ligadas ao cookie/sessão do visitante sejam
     * guardadas ou reaproveitadas por navegador, CDN ou proxy.
     *
     * @template T of Response
     *
     * @param  T  $response
     * @return T
     */
    public static function withoutCaching(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Vary', 'Cookie');

        return $response;
    }

    /**
     * Dados para descobrir onde o cookie ou a resposta se perde em produção.
     * Registra somente nomes de cookies e cabeçalhos de infraestrutura, nunca
     * valores de cookies, IP ou identificador completo da sessão.
     *
     * @return array<string, mixed>
     */
    public static function requestDiagnostics(Request $request): array
    {
        $sessionCookie = (string) config('session.cookie');
        $sessionId = $request->hasSession() ? $request->session()->getId() : '';

        return [
            'method' => $request->method(),
            'host' => $request->getHost(),
            'cookie_names' => array_keys($request->cookies->all()),
            'session_cookie_name' => $sessionCookie,
            'session_cookie_received' => $request->cookies->has($sessionCookie),
            'session_id_hash' => $sessionId !== '' ? substr(hash('sha256', $sessionId), 0, 8) : null,
            'sec_fetch' => [
                'site' => $request->header('sec-fetch-site'),
                'mode' => $request->header('sec-fetch-mode'),
                'dest' => $request->header('sec-fetch-dest'),
            ],
            'edge' => [
                'cf_ray' => $request->header('cf-ray'),
                'cf_cache_status' => $request->header('cf-cache-status'),
                'via' => $request->header('via'),
                'x_forwarded_proto' => $request->header('x-forwarded-proto'),
                'x_forwarded_host' => $request->header('x-forwarded-host'),
            ],
        ];
    }

    /**
     * Os termos valem se a versão atual estiver no cookie ou na sessão.
     * Aceitar ou recusar a localização não altera esse resultado.
     */
    public static function isAccepted(Request $request): bool
    {
        if ($request->cookie(self::TERMS_COOKIE) === self::TERMS_VERSION) {
            return true;
        }

        return $request->hasSession()
            && $request->session()->get(self::TERMS_SESSION_KEY) === self::TERMS_VERSION;
    }

    /**
     * O aviso de cookies já foi confirmado (cookie ou sessão).
     */
    public static function cookieNoticeAcknowledged(Request $request): bool
    {
        if ($request->cookie(self::COOKIE_NOTICE_COOKIE) === '1') {
            return true;
        }

        return $request->hasSession()
            && $request->session()->get(self::COOKIE_NOTICE_SESSION_KEY) === true;
    }

    /**
     * O aviso de cookies deve aparecer para quem já passou pelas boas-vindas
     * e ainda não o confirmou, tenha ativado a localização ou não.
     */
    public static function shouldShowCookieNotice(Request $request): bool
    {
        return !$request->routeIs('welcome')
            && self::isAccepted($request)
            && !self::cookieNoticeAcknowledged($request);
    }
}
