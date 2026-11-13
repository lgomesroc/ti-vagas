<?php

namespace App\Http\Controllers;

use App\Services\Geo\MunicipalityDirectory;
use App\Services\Geo\Security\LocationGuard;
use App\Services\Geo\Security\LocationSample;
use App\Services\Geo\VisitorLocationResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LocationRefreshController extends Controller
{
    public function __construct(
        private readonly VisitorLocationResolver $visitorLocationResolver,
        private readonly MunicipalityDirectory $directory,
        private readonly LocationGuard $guard
    ) {
    }

    public function refresh(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['required', 'numeric', 'min:0'],
            // Hora da leitura segundo o navegador (ms). Só ajuda a detectar
            // reenvio de posição antiga; nunca é usada para decidir a região.
            'captured_at' => ['nullable', 'numeric', 'min:0'],
        ]);

        if ($validator->fails()) {
            // O navegador legítimo nunca envia este formato.
            $this->guard->registerSuspicion($request, 'malformed_payload');

            throw new ValidationException($validator);
        }

        $data = $validator->validated();

        $session = $request->session();

        // Permite a primeira localização em modo limitado. A sessão só será
        // promovida para available depois que as coordenadas forem validadas
        // e um município brasileiro plausível for resolvido.
        if (!in_array($session->get('location_mode'), ['limited', 'available'], true)) {
            return $this->unchanged('disabled');
        }

        if (
            (float) $data['accuracy']
            > VisitorLocationResolver::MAX_ACCURACY_METERS
        ) {
            return $this->unchanged('inaccurate');
        }

        if ($this->guard->isLocked($session)) {
            return $this->unchanged('unverified');
        }

        $latitude = (float) $data['latitude'];
        $longitude = (float) $data['longitude'];

        // Cidade e UF enviadas pelo navegador não participam: a região sai
        // somente das coordenadas, e ainda assim só se passarem pelo guard.
        $municipality = $this->visitorLocationResolver->resolve(
            $latitude,
            $longitude
        );

        if ($municipality === null) {
            $this->guard->registerSuspicion($request, 'unresolvable_coordinates');

            return $this->unchanged('unresolved');
        }

        $sample = new LocationSample(
            latitude: $latitude,
            longitude: $longitude,
            accuracyMeters: (float) $data['accuracy'],
            capturedAtMs: isset($data['captured_at'])
                ? (int) $data['captured_at']
                : null,
            receivedAtMs: (int) round(microtime(true) * 1000),
        );

        $assessment = $this->guard->assess($request, $sample, $municipality);

        if (!$assessment->allowed) {
            // O motivo fica só no log: não ajuda quem tenta contornar.
            return $this->unchanged('unverified');
        }

        $this->guard->recordTrustedFix($session, $sample);

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

        // Percorrer coordenadas para "visitar" várias regiões em sequência
        // é o uso abusivo mais barato desta rota: limita as trocas.
        if ($this->guard->regionChangeLimitReached($request)) {
            $this->guard->registerSuspicion($request, 'region_hopping');

            return $this->unchanged('unverified');
        }

        $this->visitorLocationResolver->rememberInSession(
            $session,
            $municipality,
            $latitude,
            $longitude
        );

        $this->guard->recordRegionChange($request);

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
            ->header('Cache-Control', 'no-store, private');
    }
}
