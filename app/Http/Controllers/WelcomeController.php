<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureWelcomeAccepted;
use App\Services\Geo\IpLocationResolver;
use App\Services\Geo\VisitorLocationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class WelcomeController extends Controller
{
    public function __construct(
        private readonly VisitorLocationResolver $visitorLocationResolver,
        private readonly IpLocationResolver $ipLocationResolver
    ) {
    }

    public function show(Request $request): View
    {
        $termsAccepted = $this->termsAccepted($request);

        return view('welcome', compact('termsAccepted'));
    }

    public function accept(Request $request): RedirectResponse|JsonResponse
    {
        $termsAlreadyAccepted = $this->termsAccepted($request);

        $validated = $request->validate([
            'terms' => [$termsAlreadyAccepted ? 'sometimes' : 'required', 'accepted'],
            'location_status' => ['required', 'in:available,limited'],
            'latitude' => [
                'required_if:location_status,available',
                'nullable',
                'numeric',
                'between:-90,90',
            ],
            'longitude' => [
                'required_if:location_status,available',
                'nullable',
                'numeric',
                'between:-180,180',
            ],
            'city_name' => ['nullable', 'string', 'max:150'],
            'state_code' => [
                'required_if:location_status,available',
                'nullable',
                'string',
                'in:AC,AL,AP,AM,BA,CE,DF,ES,GO,MA,MT,MS,MG,PA,PB,PR,PE,PI,RJ,RN,RS,RO,RR,SC,SP,SE,TO',
            ],
        ], [
            'terms.accepted' => 'Você precisa aceitar os Termos de Uso para continuar.',
            'location_status.required' => 'Informe se deseja utilizar a localização.',
            'state_code.required_if' => 'Não foi possível identificar seu estado. Você pode continuar com experiência limitada.',
        ]);

        $request->session()->put(
            EnsureWelcomeAccepted::TERMS_SESSION_KEY,
            EnsureWelcomeAccepted::TERMS_VERSION
        );

        $locationMode = $validated['location_status'];
        $notice = null;
        $municipality = null;

        if ($locationMode === 'available') {
            $municipality = $this->visitorLocationResolver->resolve(
                (float) $validated['latitude'],
                (float) $validated['longitude'],
                $validated['city_name'] ?? null,
                $validated['state_code']
            );

            if ($municipality === null) {
                $locationMode = 'limited';
                $notice = 'Não foi possível confirmar seu município. Você continuará vendo apenas vagas remotas nacionais. Ative a localização novamente para liberar as vagas da sua região.';
            }
        }

        $request->session()->put('location_mode', $locationMode);

        if ($municipality !== null) {
            $request->session()->put([
                'location_latitude' => (float) $validated['latitude'],
                'location_longitude' => (float) $validated['longitude'],
                'visitor_state_code' => $municipality['uf'],
                'visitor_municipality_ibge' => $municipality['ibge'],
                'visitor_location_label' => $municipality['name'] . ' - ' . $municipality['uf'],
                'ip_location_label' => null,
            ]);
        } else {
            $request->session()->forget([
                'location_latitude',
                'location_longitude',
                'visitor_state_code',
                'visitor_municipality_ibge',
            ]);

            $ipLocation = $this->ipLocationResolver->resolve($request);

            $request->session()->put([
                'visitor_location_label' => null,
                'ip_location_label' => $ipLocation['label'] ?? null,
            ]);

            $notice ??= $ipLocation !== null
                ? 'Localização aproximada pelo IP: ' . $ipLocation['label'] . '. Essa estimativa não libera vagas presenciais ou híbridas. Ative a localização para visualizar oportunidades da sua região.'
                : 'A localização não foi ativada. No momento, você verá somente vagas remotas nacionais. Ative a localização para visualizar oportunidades da sua região.';
        }

        $termsCookie = cookie()->forever(
            EnsureWelcomeAccepted::TERMS_COOKIE,
            EnsureWelcomeAccepted::TERMS_VERSION,
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            'lax'
        );

        if ($request->expectsJson()) {
            $request->session()->flash('location_notice', $notice);
        }

        // Diagnóstico temporário: registrar o resultado do aceite.
        Log::warning('TI_VAGAS_WELCOME_ACCEPT', [
            'terms_already_accepted' => $termsAlreadyAccepted,
            'terms_session_after_write' => $request->session()->get(
                EnsureWelcomeAccepted::TERMS_SESSION_KEY
            ) === EnsureWelcomeAccepted::TERMS_VERSION,
            'session_available' => $request->hasSession(),
            'session_driver' => config('session.driver'),
            'request_secure' => $request->isSecure(),
            'forwarded_proto' => $request->header('x-forwarded-proto'),
            'expects_json' => $request->expectsJson(),
            'cookie_secure' => $request->isSecure(),
            'location_mode' => $locationMode,
        ]);

        if ($request->expectsJson()) {
            return response()
                ->json(['redirect' => route('vacancies.index')])
                ->withCookie($termsCookie);
        }

        return redirect()
            ->route('vacancies.index')
            ->with('location_notice', $notice)
            ->withCookie($termsCookie);
    }

    public function acknowledgeCookies(Request $request): RedirectResponse
    {
        $request->session()->put(
            EnsureWelcomeAccepted::COOKIE_NOTICE_SESSION_KEY,
            true
        );

        return redirect()
            ->back(fallback: route('vacancies.index'))
            ->withCookie(cookie()->forever(
                EnsureWelcomeAccepted::COOKIE_NOTICE_COOKIE,
                '1',
                '/',
                null,
                $request->isSecure(),
                true,
                false,
                'lax'
            ));
    }

    private function termsAccepted(Request $request): bool
    {
        return EnsureWelcomeAccepted::isAccepted($request);
    }
}
