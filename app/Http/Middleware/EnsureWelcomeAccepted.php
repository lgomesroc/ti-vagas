<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
        if (!self::isAccepted($request)) {
            return redirect()->route('welcome');
        }

        return $next($request);
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
