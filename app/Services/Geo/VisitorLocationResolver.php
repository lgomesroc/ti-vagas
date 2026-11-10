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

    public function __construct(
        private readonly MunicipalityDirectory $directory
    ) {
    }

    /**
     * Usa o nome do município informado pela geocodificação e, se ele não
     * for encontrado, o município de centroide mais próximo.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(
        float $latitude,
        float $longitude,
        ?string $cityName = null,
        ?string $stateCode = null
    ): ?array {
        $stateCode = $stateCode !== null && $stateCode !== ''
            ? strtoupper($stateCode)
            : null;

        if ($cityName !== null && trim($cityName) !== '') {
            $matches = $this->directory->findByName($cityName, $stateCode);

            if (count($matches) === 1) {
                return $matches[0];
            }
        }

        if ($stateCode === null && !$this->isInsideBrazil($latitude, $longitude)) {
            return null;
        }

        return $this->directory->nearest($latitude, $longitude, $stateCode);
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

    private function isInsideBrazil(float $latitude, float $longitude): bool
    {
        return $latitude >= self::BRAZIL_BOUNDS['min_lat']
            && $latitude <= self::BRAZIL_BOUNDS['max_lat']
            && $longitude >= self::BRAZIL_BOUNDS['min_lon']
            && $longitude <= self::BRAZIL_BOUNDS['max_lon'];
    }
}
