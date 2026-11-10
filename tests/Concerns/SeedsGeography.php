<?php

namespace Tests\Concerns;

use App\Models\Municipality;
use App\Services\Geo\IbgeGeographyImporter;
use App\Services\Geo\MunicipalityDirectory;

/**
 * Carrega a base real de municípios (CSV) e os ajustes de RMs e RIDEs.
 *
 * As regiões intermediárias abaixo são FIXTURES de teste (códigos fictícios),
 * usadas só para exercitar a regra de regionalização.
 */
trait SeedsGeography
{
    /**
     * @return list<string> membros dos ajustes não encontrados na base
     */
    protected function seedGeography(): array
    {
        $importer = app(IbgeGeographyImporter::class);

        $importer->importMunicipalities();

        $importer->applyMunicipalityRegions($this->intermediateRegionFixture());

        return $importer->applyCuratedUnits();
    }

    protected function ibge(string $name, string $stateCode): string
    {
        return Municipality::query()
            ->where('state_code', $stateCode)
            ->where('normalized_name', MunicipalityDirectory::normalize($name))
            ->value('ibge_code');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function intermediateRegionFixture(): array
    {
        $groups = [
            // [nome, UF, região imediata, região intermediária]
            ['Rio de Janeiro', 'RJ', 'I-RIO', 'INT-RIO'],
            ['Niterói', 'RJ', 'I-NIT', 'INT-RIO'],
            ['Rio Bonito', 'RJ', 'I-RBO', 'INT-RIO'],
            ['Teresópolis', 'RJ', 'I-TER', 'INT-SERRA'],
            ['Nova Friburgo', 'RJ', 'I-NFR', 'INT-SERRA'],
            ['Resende', 'RJ', 'I-RES', 'INT-VOLTA'],
            ['Pelotas', 'RS', 'I-PEL', 'INT-PELOTAS'],
            ['Rio Grande', 'RS', 'I-RGR', 'INT-PELOTAS'],
            ['Santa Maria', 'RS', 'I-SMA', 'INT-SMARIA'],
            ['Porto Alegre', 'RS', 'I-POA', 'INT-POA'],
            ['Santos', 'SP', 'I-SAN', 'INT-SANTOS'],
            ['Goiânia', 'GO', 'I-GYN', 'INT-GYN'],
        ];

        return array_map(fn (array $row): array => [
            'id' => $this->ibge($row[0], $row[1]),
            'nome' => $row[0],
            'regiao-imediata' => [
                'id' => $row[2],
                'nome' => 'Imediata ' . $row[0],
                'regiao-intermediaria' => [
                    'id' => $row[3],
                    'nome' => 'Intermediária ' . $row[3],
                ],
            ],
        ], $groups);
    }
}
