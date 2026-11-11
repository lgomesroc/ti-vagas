<?php

namespace App\Services\Geo;

use Illuminate\Contracts\Session\Session;

/**
 * Descobre o município do visitante a partir da geolocalização.
 */
class VisitorLocationResolver
{
    // Caixa aproximada do território brasileiro.
    private const BRAZIL_BOUNDS = [
        'min_lat' => -34.0,
        'max_lat' => 6.0,
        'min_lon' => -74.5,
        'max_lon' => -28.0,
    ];

    /**
     * Distância máxima aceita entre as coordenadas informadas e o centroide
     * do município, conforme a região de atuação que ele libera.
     *
     * Município sem RM nem RIDE tem tolerância maior: costuma ser grande ou
     * rural, e seu centroide fica longe de quem mora em outra ponta dele.
     */
    public const MAX_DISTANCE_KM_WITHOUT_REGION = 200.0;

    public const MAX_DISTANCE_KM_WITH_REGION = 100.0;

    /**
     * Precisão máxima (coords.accuracy, em metros) para atualizar a região
     * automaticamente. Acima disso a posição é vaga demais (Wi-Fi/IP) e a
     * região não é alterada.
     */
    public const MAX_ACCURACY_METERS = 5000.0;

    /**
     * Deslocamento mínimo (km) em relação à posição que definiu a região
     * atual para que uma região diferente seja aceita. Evita oscilação entre
     * municípios vizinhos quando o visitante está parado.
     */
    public const MIN_MOVE_KM = 5.0;

    public function __construct(
        private readonly MunicipalityDirectory $directory
    ) {
    }

    /**
     * Descobre o município do visitante sem confiar no que o navegador diz.
     *
     * O nome e a UF vêm do navegador (podem ser forjados), por isso só valem
     * se forem coerentes com as coordenadas: o município declarado precisa
     * estar dentro da distância máxima. Se não estiver, usa o município de
     * centroide mais próximo das coordenadas, também dentro do limite.
     * Coordenadas fora do Brasil ou sem município plausível devolvem null.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(
        float $latitude,
        float $longitude,
        ?string $cityName = null,
        ?string $stateCode = null
    ): ?array {
        if (!$this->isInsideBrazil($latitude, $longitude)) {
            return null;
        }

        $stateCode = $stateCode !== null && $stateCode !== ''
            ? strtoupper($stateCode)
            : null;

        if ($cityName !== null && trim($cityName) !== '') {
            $declared = $this->closestTo(
                $this->directory->findByName($cityName, $stateCode),
                $latitude,
                $longitude
            );

            if (
                $declared !== null
                && $this->isWithinLimit($declared, $latitude, $longitude)
            ) {
                return $declared;
            }
        }

        $nearest = $this->directory->nearest($latitude, $longitude, $stateCode);

        if (
            $nearest !== null
            && $this->isWithinLimit($nearest, $latitude, $longitude)
        ) {
            return $nearest;
        }

        return null;
    }

    /**
     * Região do visitante guardada na sessão.
     *
     * Sessões antigas, que só têm coordenadas e UF, também são resolvidas.
     */
    public function scopeFromSession(Session $session): ?RegionScope
    {
        if ($session->get('location_mode') !== 'available') {
            return null;
        }

        $municipality = $this->directory->find(
            $session->get('visitor_municipality_ibge')
        );

        if (
            $municipality === null
            && $session->has('location_latitude')
            && $session->has('location_longitude')
        ) {
            $municipality = $this->resolve(
                (float) $session->get('location_latitude'),
                (float) $session->get('location_longitude'),
                null,
                $session->get('visitor_state_code')
            );
        }

        return $municipality !== null
            ? $this->directory->scopeFor($municipality)
            : null;
    }

    /**
     * Grava na sessão a localização verificada do visitante.
     *
     * @param array<string, mixed> $municipality
     */
    public function rememberInSession(
        Session $session,
        array $municipality,
        float $latitude,
        float $longitude
    ): void {
        $session->put([
            'location_mode' => 'available',
            'location_latitude' => $latitude,
            'location_longitude' => $longitude,
            'visitor_state_code' => $municipality['uf'],
            'visitor_municipality_ibge' => $municipality['ibge'],
            'visitor_location_label' => $municipality['name'] . ' - ' . $municipality['uf'],
            'ip_location_label' => null,
        ]);
    }

    /**
     * Distância (km) entre as coordenadas e a posição que definiu a região
     * guardada na sessão, ou null se a sessão não tem coordenadas.
     */
    public function distanceFromSessionKm(
        Session $session,
        float $latitude,
        float $longitude
    ): ?float {
        if (!$session->has('location_latitude') || !$session->has('location_longitude')) {
            return null;
        }

        return $this->directory->distanceKm(
            (float) $session->get('location_latitude'),
            (float) $session->get('location_longitude'),
            $latitude,
            $longitude
        );
    }

    /**
     * @param list<array<string, mixed>> $municipalities
     * @return array<string, mixed>|null
     */
    private function closestTo(
        array $municipalities,
        float $latitude,
        float $longitude
    ): ?array {
        $best = null;
        $bestDistance = INF;

        foreach ($municipalities as $municipality) {
            $distance = $this->directory->distanceToKm(
                $municipality,
                $latitude,
                $longitude
            );

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $municipality;
            }
        }

        return $best;
    }

    /**
     * @param array<string, mixed> $municipality
     */
    private function isWithinLimit(
        array $municipality,
        float $latitude,
        float $longitude
    ): bool {
        $limit = empty($municipality['region_code'])
            ? self::MAX_DISTANCE_KM_WITHOUT_REGION
            : self::MAX_DISTANCE_KM_WITH_REGION;

        return $this->directory->distanceToKm(
            $municipality,
            $latitude,
            $longitude
        ) <= $limit;
    }

    private function isInsideBrazil(float $latitude, float $longitude): bool
    {
        return $latitude >= self::BRAZIL_BOUNDS['min_lat']
            && $latitude <= self::BRAZIL_BOUNDS['max_lat']
            && $longitude >= self::BRAZIL_BOUNDS['min_lon']
            && $longitude <= self::BRAZIL_BOUNDS['max_lon'];
    }
}
