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
        $acceptedVersion = $request->cookie('ti_vagas_terms_accepted');

        if ($acceptedVersion !== self::TERMS_VERSION) {
            return redirect()->route('welcome');
        }

        return $next($request);
    }
}
