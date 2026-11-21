<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureWelcomeAccepted;
use App\Services\Geo\VisitorLocationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cookie;

class WelcomeController extends Controller
{
    public function __construct(
        private readonly VisitorLocationResolver $visitorLocationResolver
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        if (EnsureWelcomeAccepted::isAccepted($request)) {
            return redirect()->route('vacancies.index');
        }

        return response()
            ->view('welcome', [
                'termsAccepted' => false,
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private')
            ->header('Pragma', 'no-cache')
            ->header('Vary', 'Cookie');
    }

    public function accept(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate([
            'terms' => ['required', 'accepted'],
        ]);

        $request->session()->put(
            EnsureWelcomeAccepted::TERMS_SESSION_KEY,
            EnsureWelcomeAccepted::TERMS_VERSION
        );

        // A localização será solicitada somente na página de vagas.
        // Até que o visitante autorize, a experiência fica limitada às vagas remotas.
        // Também limpa as chaves atuais e os nomes legados de versões anteriores.
        // O estado do LocationGuard não é apagado aqui: aceitar os termos novamente
        // não pode funcionar como mecanismo para contornar uma trava antifraude.
        //
        // Não há estimativa de localização por IP: a região do visitante só
        // existe depois que o navegador informa uma posição e o servidor a valida.
        $this->visitorLocationResolver->forgetFromSession($request->session());

        $secureCookie = config('session.secure') ?? $request->isSecure();

        Cookie::queue(
            cookie(
                EnsureWelcomeAccepted::TERMS_COOKIE,
                EnsureWelcomeAccepted::TERMS_VERSION,
                60 * 24 * 365 * 5,
                config('session.path', '/'),
                config('session.domain'),
                $secureCookie,
                config('session.http_only', true),
                false,
                config('session.same_site', 'lax')
            )
        );

        if ($request->expectsJson()) {
            return response()->json([
                'accepted' => true,
                'redirect' => route('vacancies.index'),
            ])
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private')
                ->header('Pragma', 'no-cache')
                ->header('Vary', 'Cookie');
        }

        return redirect()
            ->route('vacancies.index')
            ->with('welcome_notice', 'Termos aceitos. Você já pode consultar as vagas.')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private')
            ->header('Pragma', 'no-cache')
            ->header('Vary', 'Cookie');
    }

    public function acknowledgeCookies(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'optional_cookies' => ['required', 'in:accepted,rejected'],
        ]);

        $choice = $validated['optional_cookies'];

        $request->session()->put(
            EnsureWelcomeAccepted::COOKIE_NOTICE_SESSION_KEY,
            $choice
        );

        $secureCookie = config('session.secure') ?? $request->isSecure();

        Cookie::queue(
            cookie(
                EnsureWelcomeAccepted::COOKIE_NOTICE_COOKIE,
                $choice,
                60 * 24 * 365,
                config('session.path', '/'),
                config('session.domain'),
                $secureCookie,
                config('session.http_only', true),
                false,
                config('session.same_site', 'lax')
            )
        );

        return EnsureWelcomeAccepted::withoutCaching(back());
    }
}
