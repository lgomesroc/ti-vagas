<?php

namespace Tests\Concerns;

use App\Services\Geo\MunicipalityDirectory;
use App\Services\Geo\Security\LocationProof;

/**
 * Monta dados de sessão de um visitante com localização verificada, no mesmo
 * formato (e com o mesmo selo de integridade) que o servidor grava.
 */
trait BuildsVerifiedLocationSession
{
    /**
     * @param array{0: float, 1: float}|null $coordinates Padrão: centroide.
     * @return array<string, mixed>
     */
    protected function verifiedLocationSession(string $ibge, ?array $coordinates = null): array
    {
        $municipality = app(MunicipalityDirectory::class)->find($ibge);

        $this->assertNotNull($municipality, "Município {$ibge} não encontrado.");

        $latitude = $coordinates[0] ?? (float) $municipality['latitude'];
        $longitude = $coordinates[1] ?? (float) $municipality['longitude'];

        return [
            'location_mode' => 'available',
            'location_latitude' => $latitude,
            'location_longitude' => $longitude,
            'visitor_state_code' => $municipality['uf'],
            'visitor_municipality_ibge' => $ibge,
            LocationProof::SESSION_KEY => app(LocationProof::class)->issue(
                $municipality,
                $latitude,
                $longitude
            ),
        ];
    }
}
