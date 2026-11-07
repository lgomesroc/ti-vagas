<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureWelcomeAccepted;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WelcomeController extends Controller
{
    public function show(): View
    {
        return view('welcome');
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
        ], [
            'terms.accepted' => 'Você precisa aceitar os Termos de Uso para continuar.',
            'location_status.required' => 'Informe se deseja utilizar a localização.',
        ]);

        $request->session()->put([
            'location_mode' => $validated['location_status'],
        ]);

        if ($validated['location_status'] === 'available') {
            $request->session()->put([
                'location_latitude' => (float) $validated['latitude'],
                'location_longitude' => (float) $validated['longitude'],
            ]);
        } else {
            $request->session()->forget([
                'location_latitude',
                'location_longitude',
            ]);
        }

        return redirect()
            ->route('vacancies.index')
            ->withCookie(cookie(
                'ti_vagas_terms_accepted',
                EnsureWelcomeAccepted::TERMS_VERSION,
                525600,
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
            ->route(
                $request->cookie('ti_vagas_terms_accepted') === EnsureWelcomeAccepted::TERMS_VERSION
                    ? 'vacancies.index'
                    : 'welcome'
            )
            ->withCookie(cookie(
                'ti_vagas_cookie_notice',
                '1',
                525600,
                '/',
                null,
                $request->isSecure(),
                true,
                false,
                'lax'
            ));
    }
}
