<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWelcomeAccepted
{
    public const TERMS_VERSION = '1.0';

    public function handle(Request $request, Closure $next): Response
    {
        $acceptedByCookie = $request->cookie('ti_vagas_terms_accepted')
            === self::TERMS_VERSION;
        $acceptedBySession = $request->session()->get('terms_accepted_version')
            === self::TERMS_VERSION;

        if (!$acceptedByCookie && !$acceptedBySession) {
            return redirect()->route('welcome');
        }

        return $next($request);
    }
}
