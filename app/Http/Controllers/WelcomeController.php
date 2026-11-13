<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureWelcomeAccepted;
use App\Http\Middleware\EvaluateNetworkReputation;
use App\Services\Geo\IpLocationResolver;
use App\Services\Network\NetworkAssessment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cookie;

class WelcomeController extends Controller
{
    public function __construct(
        private readonly IpLocationResolver $ipLocationResolver
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
            ->header('Pragma', 'no-cache');
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
        $request->session()->put('location_mode', 'limited');

        $request->session()->forget([
            'visitor_latitude',
            'visitor_longitude',
            'visitor_state_code',
            'visitor_ibge_code',
            'visitor_city_name',
            'visitor_region_label',
            'visitor_ip_region_label',
        ]);

        // A estimativa por IP é apenas informativa.
        // Ela não habilita vagas presenciais ou híbridas.
        // Em VPN, proxy, Tor ou datacenter o IP é o do ponto de saída, não o
        // do visitante: nesses casos a estimativa seria enganosa e é omitida.
        $assessment = $request->attributes->get(EvaluateNetworkReputation::ATTRIBUTE);

        $ipLocation = ! $assessment instanceof NetworkAssessment || $assessment->isOrdinary()
            ? $this->ipLocationResolver->resolve($request)
            : null;

        if ($ipLocation !== null) {
            $request->session()->put(
                'visitor_ip_region_label',
                $ipLocation['label']
            );
        }

        Cookie::queue(
            cookie(
                EnsureWelcomeAccepted::TERMS_COOKIE,
                EnsureWelcomeAccepted::TERMS_VERSION,
                60 * 24 * 365 * 5,
                '/',
                null,
                $request->isSecure(),
                true,
                false,
                'lax'
            )
        );

        if ($request->expectsJson()) {
            return response()->json([
                'accepted' => true,
                'redirect' => route('vacancies.index'),
            ]);
        }

        return redirect()
            ->route('vacancies.index')
            ->with('welcome_notice', 'Termos aceitos. Você já pode consultar as vagas.');
    }

    public function acknowledgeCookies(Request $request): RedirectResponse
    {
        $request->session()->put(
            EnsureWelcomeAccepted::COOKIE_NOTICE_SESSION_KEY,
            true
        );

        Cookie::queue(
            cookie(
                EnsureWelcomeAccepted::COOKIE_NOTICE_COOKIE,
                '1',
                60 * 24 * 365,
                '/',
                null,
                $request->isSecure(),
                true,
                false,
                'lax'
            )
        );

        return back();
    }
}
