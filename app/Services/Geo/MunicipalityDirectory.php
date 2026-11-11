<?php

namespace App\Services\Geo;

use App\Models\Municipality;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Consulta em memória à base de municípios do IBGE.
 *
 * Os ~5.570 municípios são carregados uma vez (e mantidos em cache)
 * para que a resolução de localização não faça uma consulta por vaga.
 */
class MunicipalityDirectory
{
    private const CACHE_KEY = 'geo.municipalities.v1';

    /** @var array{byIbge: array<string, array>, byName: array<string, list<string>>}|null */
    private ?array $data = null;

    public static function normalize(?string $value): string
    {
        $value = Str::ascii(mb_strtolower($value ?? ''));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';

        return trim($value);
    }

    public function flush(): void
    {
        $this->data = null;

        Cache::forget(self::CACHE_KEY);
    }

    public function isEmpty(): bool
    {
        return $this->load()['byIbge'] === [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(?string $ibgeCode): ?array
    {
        if ($ibgeCode === null || $ibgeCode === '') {
            return null;
        }

        return $this->load()['byIbge'][$ibgeCode] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findByName(string $name, ?string $stateCode = null): array
    {
        $data = $this->load();
        $codes = $data['byName'][self::normalize($name)] ?? [];
        $stateCode = $stateCode !== null ? strtoupper($stateCode) : null;
        $result = [];

        foreach ($codes as $code) {
            $municipality = $data['byIbge'][$code];

            if (
                $stateCode === null
                || $municipality['uf'] === $stateCode
            ) {
                $result[] = $municipality;
            }
        }

        return $result;
    }

    /**
     * Município cujo centroide é o mais próximo das coordenadas.
     *
     * @return array<string, mixed>|null
     */
    public function nearest(
        float $latitude,
        float $longitude,
        ?string $stateCode = null
    ): ?array {
        $stateCode = $stateCode !== null ? strtoupper($stateCode) : null;
        $best = null;
        $bestDistance = INF;

        foreach ($this->load()['byIbge'] as $municipality) {
            if ($stateCode !== null && $municipality['uf'] !== $stateCode) {
                continue;
            }

            $distance = $this->distanceKm(
                $latitude,
                $longitude,
                $municipality['latitude'],
                $municipality['longitude']
            );

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $municipality;
            }
        }

        return $best;
    }

    /**
     * Região de atuação de quem está no município informado.
     *
     * @param array<string, mixed> $municipality
     */
    public function scopeFor(array $municipality): RegionScope
    {
        if (!empty($municipality['region_code'])) {
            return new RegionScope(
                RegionScope::UNIT,
                $municipality['region_code'],
                $municipality['region_name'] ?? $municipality['region_code']
            );
        }

        if (!empty($municipality['intermediate_region_code'])) {
            return new RegionScope(
                RegionScope::INTERMEDIATE,
                $municipality['intermediate_region_code'],
                $municipality['intermediate_region_name']
                    ?? $municipality['intermediate_region_code']
            );
        }

        return new RegionScope(
            RegionScope::MUNICIPALITY,
            $municipality['ibge'],
            $municipality['name']
        );
    }

    /**
     * Distância, em km, entre as coordenadas e o centroide do município.
     *
     * @param array<string, mixed> $municipality
     */
    public function distanceToKm(
        array $municipality,
        float $latitude,
        float $longitude
    ): float {
        return $this->distanceKm(
            $latitude,
            $longitude,
            (float) $municipality['latitude'],
            (float) $municipality['longitude']
        );
    }

    private function distanceKm(
        float $lat1,
        float $lon1,
        float $lat2,
        float $lon2
    ): float {
        $earthRadius = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLon / 2) ** 2;

        return 2 * $earthRadius * asin(min(1.0, sqrt($a)));
    }

    /**
     * @return array{byIbge: array<string, array>, byName: array<string, list<string>>}
     */
    private function load(): array
    {
        return $this->data ??= Cache::remember(
            self::CACHE_KEY,
            now()->addHours(6),
            fn (): array => $this->build()
        );
    }

    /**
     * @return array{byIbge: array<string, array>, byName: array<string, list<string>>}
     */
    private function build(): array
    {
        $byIbge = [];
        $byName = [];

        Municipality::query()
            ->orderBy('id')
            ->each(function (Municipality $municipality) use (&$byIbge, &$byName): void {
                $code = (string) $municipality->ibge_code;

                $byIbge[$code] = [
                    'ibge' => $code,
                    'name' => $municipality->name,
                    'uf' => $municipality->state_code,
                    'state_code' => $municipality->state_code,
                    'latitude' => (float) $municipality->latitude,
                    'longitude' => (float) $municipality->longitude,
                    'intermediate_region_code' => $municipality->intermediate_region_code,
                    'intermediate_region_name' => $municipality->intermediate_region_name,
                    'immediate_region_code' => $municipality->immediate_region_code,
                    'region_type' => $municipality->region_type,
                    'region_code' => $municipality->region_code,
                    'region_name' => $municipality->region_name,
                ];

                $byName[$municipality->normalized_name][] = $code;
            });

        return ['byIbge' => $byIbge, 'byName' => $byName];
    }
}
