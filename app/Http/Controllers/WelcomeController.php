<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureWelcomeAccepted;
use App\Services\Geo\VisitorLocationResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WelcomeController extends Controller
{
    public function __construct(
        private readonly VisitorLocationResolver $visitorLocationResolver
    ) {
    }

    public function show(Request $request): View
    {
        $termsAccepted = $request->cookie('ti_vagas_terms_accepted')
            === EnsureWelcomeAccepted::TERMS_VERSION;

        return view('welcome', compact('termsAccepted'));
    }

    public function accept(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'terms' => ['required', 'accepted'],
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
            'state_code.required_if' => 'Não foi possível identificar seu estado. Tente obter a localização novamente.',
        ]);

        $municipality = null;

        if ($validated['location_status'] === 'available') {
            $municipality = $this->visitorLocationResolver->resolve(
                (float) $validated['latitude'],
                (float) $validated['longitude'],
                $validated['city_name'] ?? null,
                $validated['state_code']
            );

            if ($municipality === null) {
                return back()
                    ->withInput()
                    ->withErrors([
                        'state_code' => 'Não foi possível identificar seu município. Tente obter a localização novamente.',
                    ]);
            }
        }

        $request->session()->put([
            'location_mode' => $validated['location_status'],
        ]);

        if ($municipality !== null) {
            $request->session()->put([
                'location_latitude' => (float) $validated['latitude'],
                'location_longitude' => (float) $validated['longitude'],
                'visitor_state_code' => $municipality['uf'],
                'visitor_municipality_ibge' => $municipality['ibge'],
            ]);
        } else {
            $request->session()->forget([
                'location_latitude',
                'location_longitude',
                'visitor_state_code',
                'visitor_municipality_ibge',
            ]);
        }

        return redirect()
            ->route('vacancies.index')
            ->withCookie(cookie()->forever(
                'ti_vagas_terms_accepted',
                EnsureWelcomeAccepted::TERMS_VERSION,
                '/',
                null,
                $request->isSecure(),
                true,
                false,
                'lax'
            ));
    }

    public function acknowledgeCookies(Request $request): RedirectResponse
    {
        return redirect()
            ->back()
            ->withCookie(cookie()->forever(
                'ti_vagas_cookie_notice',
                '1',
                '/',
                null,
                $request->isSecure(),
                true,
                false,
                'lax'
            ));
    }
}
