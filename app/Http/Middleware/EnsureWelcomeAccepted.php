<?php

namespace App\\Http\\Middleware;

use Closure;
use Illuminate\\Http\\Request;
use Symfony\\Component\\HttpFoundation\\Response;

class EnsureWelcomeAccepted
{
    public const TERMS_VERSION = '1.0';

    public const TERMS_COOKIE = 'ti_vagas_terms_accepted';

    public const TERMS_SESSION_KEY = 'terms_accepted_version';

    public const COOKIE_NOTICE_COOKIE = 'ti_vagas_cookie_notice';

    public const COOKIE_NOTICE_SESSION_KEY = 'optional_cookies_choice';

    public function handle(Request $request, Closure $next): Response
    {
        if (!self::isAccepted($request)) {
            return self::withoutCaching(
                redirect()->route('welcome')
            );
        }

        return $next($request);
    }

    /**
     * Impede o cache de respostas dependentes da sessão ou dos cookies.
     *
     * @template T of Response
     *
     * @param T $response
     * @return T
     */
    public static function withoutCaching(Response $response): Response
    {
        $response->headers->set(
            'Cache-Control',
            'no-store, no-cache, must-revalidate, max-age=0, private'
        );

        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Vary', 'Cookie');

        return $response;
    }

    /**
     * Verifica o aceite da versão atual dos termos.
     */
    public static function isAccepted(Request $request): bool
    {
        if ($request->cookie(self::TERMS_COOKIE) === self::TERMS_VERSION) {
            return true;
        }

        return $request->hasSession()
            && $request->session()->get(self::TERMS_SESSION_KEY)
                === self::TERMS_VERSION;
    }

    /**
     * Retorna uma escolha persistida válida, priorizando o cookie durável.
     */
    public static function optionalCookiesChoice(Request $request): ?string
    {
        $cookieChoice = $request->cookie(self::COOKIE_NOTICE_COOKIE);

        if (in_array($cookieChoice, ['accepted', 'rejected'], true)) {
            return $cookieChoice;
        }

        if (!$request->hasSession()) {
            return null;
        }

        $sessionChoice = $request->session()->get(self::COOKIE_NOTICE_SESSION_KEY);

        return in_array($sessionChoice, ['accepted', 'rejected'], true)
            ? $sessionChoice
            : null;
    }

    /**
     * Verifica se o aviso de cookies já foi confirmado.
     */
    public static function cookieNoticeAcknowledged(Request $request): bool
    {
        return self::optionalCookiesChoice($request) !== null;
    }

    /**
     * Verifica se o visitante autorizou cookies não essenciais.
     */
    public static function optionalCookiesAllowed(Request $request): bool
    {
        return self::optionalCookiesChoice($request) === 'accepted';
    }

    /**
     * Define se o aviso de cookies deve aparecer.
     */
    public static function shouldShowCookieNotice(Request $request): bool
    {
        return !$request->routeIs('welcome')
            && self::isAccepted($request)
            && !self::cookieNoticeAcknowledged($request);
    }
}
