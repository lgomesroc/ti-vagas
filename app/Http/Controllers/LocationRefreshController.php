<?php

namespace App\Http\Controllers;

use App\Services\Geo\MunicipalityDirectory;
use App\Services\Geo\VisitorLocationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        if (
            (float) $data['accuracy']
            > VisitorLocationResolver::MAX_ACCURACY_METERS
        ) {
            return $this->unchanged('inaccurate');
        }

        $latitude = (float) $data['latitude'];
        $longitude = (float) $data['longitude'];

        $municipality = $this->visitorLocationResolver->resolve(
            $latitude,
            $longitude
        );

        if ($municipality === null) {
            return $this->unchanged('unresolved');
        }

        $current = $this->visitorLocationResolver->scopeFromSession($session);
        $new = $this->directory->scopeFor($municipality);

        if ($current !== null && $new->equals($current)) {
            return $this->unchanged('unchanged');
        }

        if ($current !== null) {
            $moved = $this->visitorLocationResolver->distanceFromSessionKm(
                $session,
                $latitude,
                $longitude
            );

            if (
                $moved !== null
                && $moved < VisitorLocationResolver::MIN_MOVE_KM
            ) {
                return $this->unchanged('nearby');
            }
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
        return $this->respond([
            'updated' => false,
            'reason' => $reason,
        ]);
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
