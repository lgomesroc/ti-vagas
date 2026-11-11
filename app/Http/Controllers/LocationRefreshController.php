<?php

namespace App\Http\Controllers;

use App\Services\Geo\MunicipalityDirectory;
use App\Services\Geo\VisitorLocationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Atualização automática da região do visitante durante a navegação.
 *
 * O navegador envia só as coordenadas e a precisão. O município é sempre
 * descoberto aqui, com a mesma validação do aceite inicial, e a sessão só
 * muda quando a região efetivamente muda.
 */
class LocationRefreshController extends Controller
{
    public function __construct(
        private readonly VisitorLocationResolver $visitorLocationResolver,
        private readonly MunicipalityDirectory $directory
    ) {
    }

    public function refresh(Request $request): JsonResponse
    {
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['required', 'numeric', 'min:0'],
        ]);

        $session = $request->session();

        // Quem escolheu a experiência limitada não é promovido sozinho.
        if ($session->get('location_mode') !== 'available') {
            return $this->unchanged('disabled');
        }

        if ((float) $data['accuracy'] > VisitorLocationResolver::MAX_ACCURACY_METERS) {
            return $this->unchanged('inaccurate');
        }

        $latitude = (float) $data['latitude'];
        $longitude = (float) $data['longitude'];

        // Sem nome nem UF vindos do navegador: só as coordenadas.
        $municipality = $this->visitorLocationResolver->resolve($latitude, $longitude);

        if ($municipality === null) {
            return $this->unchanged('unresolved');
        }

        $current = $this->visitorLocationResolver->scopeFromSession($session);
        $new = $this->directory->scopeFor($municipality);

        if ($new->equals($current)) {
            return $this->unchanged('unchanged');
        }

        $moved = $this->visitorLocationResolver->distanceFromSessionKm(
            $session,
            $latitude,
            $longitude
        );

        if ($current !== null && $moved !== null && $moved < VisitorLocationResolver::MIN_MOVE_KM) {
            return $this->unchanged('nearby');
        }

        $this->visitorLocationResolver->rememberInSession(
            $session,
            $municipality,
            $latitude,
            $longitude
        );

        return $this->respond([
            'updated' => true,
            'region' => $new->label,
        ]);
    }

    private function unchanged(string $reason): JsonResponse
    {
        return $this->respond(['updated' => false, 'reason' => $reason]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function respond(array $payload): JsonResponse
    {
        return response()
            ->json($payload)
            ->header('Cache-Control', 'no-store');
    }
}
