<?php

namespace App\Services\Geo;

use App\Models\Municipality;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Carrega a base territorial usada na regionalização das vagas.
 *
 * 1. Municípios, UF e centroides: arquivo CSV versionado em
 *    database/data/municipios.csv.
 * 2. Regiões Geográficas Imediatas e Intermediárias (IBGE, 2017):
 *    API de localidades do IBGE.
 * 3. Regiões Metropolitanas: API de localidades do IBGE.
 * 4. Ajustes manuais (RM do Rio, SP, Campinas e RIDEs): config/geo_regions.php.
 */
class IbgeGeographyImporter
{
    private const STATE_BY_IBGE_PREFIX = [
        '11' => 'RO', '12' => 'AC', '13' => 'AM', '14' => 'RR',
        '15' => 'PA', '16' => 'AP', '17' => 'TO', '21' => 'MA',
        '22' => 'PI', '23' => 'CE', '24' => 'RN', '25' => 'PB',
        '26' => 'PE', '27' => 'AL', '28' => 'SE', '29' => 'BA',
        '31' => 'MG', '32' => 'ES', '33' => 'RJ', '35' => 'SP',
        '41' => 'PR', '42' => 'SC', '43' => 'RS', '50' => 'MS',
        '51' => 'MT', '52' => 'GO', '53' => 'DF',
    ];

    public function __construct(
        private readonly MunicipalityDirectory $directory
    ) {
    }

    public static function defaultCsvPath(): string
    {
        return database_path('data/municipios.csv');
    }

    /**
     * Importa municípios, UF e centroides a partir do CSV.
     *
     * Colunas: codigo_ibge, nome, latitude, longitude, codigo_uf.
     */
    public function importMunicipalities(?string $csvPath = null): int
    {
        $csvPath ??= self::defaultCsvPath();

        $handle = is_readable($csvPath) ? fopen($csvPath, 'r') : false;

        if ($handle === false) {
            throw new InvalidArgumentException(
                "Arquivo de municípios não encontrado: {$csvPath}"
            );
        }

        $rows = [];
        $total = 0;
        $header = null;

        while (($line = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if ($header === null) {
                $line[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $line[0]);
                $header = array_flip($line);

                continue;
            }

            if (count($line) < 5) {
                continue;
            }

            $code = str_pad(
                (string) $line[$header['codigo_ibge']],
                7,
                '0',
                STR_PAD_LEFT
            );

            $state = self::STATE_BY_IBGE_PREFIX[substr($code, 0, 2)] ?? null;

            if ($state === null) {
                continue;
            }

            $name = trim((string) $line[$header['nome']]);

            $rows[] = [
                'ibge_code' => $code,
                'name' => $name,
                'normalized_name' => MunicipalityDirectory::normalize($name),
                'state_code' => $state,
                'latitude' => (float) $line[$header['latitude']],
                'longitude' => (float) $line[$header['longitude']],
            ];

            if (count($rows) >= 500) {
                $this->upsert($rows);
                $total += count($rows);
                $rows = [];
            }
        }

        fclose($handle);

        if ($rows !== []) {
            $this->upsert($rows);
            $total += count($rows);
        }

        $this->directory->flush();

        return $total;
    }

    /**
     * Aplica as Regiões Imediatas e Intermediárias do IBGE.
     *
     * Cada item segue o formato da API de localidades:
     * { id, nome, "regiao-imediata": { id, nome,
     *   "regiao-intermediaria": { id, nome } } }
     *
     * @param iterable<array<string, mixed>> $payload
     */
    public function applyMunicipalityRegions(iterable $payload): int
    {
        $groups = [];

        foreach ($payload as $item) {
            $immediate = $item['regiao-imediata'] ?? null;

            if (!is_array($immediate) || !isset($item['id'])) {
                continue;
            }

            $intermediate = $immediate['regiao-intermediaria'] ?? [];

            $key = ($immediate['id'] ?? '') . '|' . ($intermediate['id'] ?? '');

            $groups[$key]['attributes'] = [
                'immediate_region_code' => isset($immediate['id'])
                    ? (string) $immediate['id']
                    : null,
                'immediate_region_name' => $immediate['nome'] ?? null,
                'intermediate_region_code' => isset($intermediate['id'])
                    ? (string) $intermediate['id']
                    : null,
                'intermediate_region_name' => $intermediate['nome'] ?? null,
            ];
            $groups[$key]['codes'][] = str_pad(
                (string) $item['id'],
                7,
                '0',
                STR_PAD_LEFT
            );
        }

        $updated = 0;

        DB::transaction(function () use ($groups, &$updated): void {
            foreach ($groups as $group) {
                foreach (array_chunk($group['codes'], 500) as $codes) {
                    $updated += Municipality::query()
                        ->whereIn('ibge_code', $codes)
                        ->update($group['attributes']);
                }
            }
        });

        $this->directory->flush();

        return $updated;
    }

    /**
     * Aplica as Regiões Metropolitanas do IBGE.
     *
     * Cada item: { id, nome, municipios: [ { id, ... } ] }.
     * RMs substituídas por config/geo_regions.php são ignoradas.
     *
     * @param iterable<array<string, mixed>> $regions
     * @param list<array<string, mixed>>|null $curatedUnits
     */
    public function applyMetropolitanRegions(
        iterable $regions,
        ?array $curatedUnits = null
    ): int {
        $curatedUnits ??= config('geo_regions.units', []);
        $superseded = [];

        foreach ($curatedUnits as $unit) {
            foreach ($unit['supersedes'] ?? [] as $name) {
                $superseded[] = MunicipalityDirectory::normalize($name);
            }
        }

        $updated = 0;

        DB::transaction(function () use ($regions, $superseded, &$updated): void {
            foreach ($regions as $region) {
                if (!isset($region['id'])) {
                    continue;
                }

                $name = (string) ($region['nome'] ?? $region['id']);

                if (in_array($this->regionBaseName($name), $superseded, true)) {
                    continue;
                }

                $codes = [];

                foreach ($region['municipios'] ?? [] as $member) {
                    if (isset($member['id'])) {
                        $codes[] = str_pad(
                            (string) $member['id'],
                            7,
                            '0',
                            STR_PAD_LEFT
                        );
                    }
                }

                foreach (array_chunk($codes, 500) as $chunk) {
                    $updated += Municipality::query()
                        ->whereIn('ibge_code', $chunk)
                        ->update([
                            'region_type' => 'RM',
                            'region_code' => 'RM-' . $region['id'],
                            'region_name' => $name,
                        ]);
                }
            }
        });

        $this->directory->flush();

        return $updated;
    }

    /**
     * Aplica os ajustes manuais de config/geo_regions.php.
     *
     * Retorna os membros que não foram encontrados na base de municípios.
     *
     * @param list<array<string, mixed>>|null $units
     * @return list<string>
     */
    public function applyCuratedUnits(?array $units = null): array
    {
        $units ??= config('geo_regions.units', []);
        $missing = [];

        DB::transaction(function () use ($units, &$missing): void {
            foreach ($units as $unit) {
                $this->clearSupersededRegions($unit['supersedes'] ?? []);

                foreach ($unit['members'] as $stateCode => $names) {
                    foreach ($names as $name) {
                        $municipality = Municipality::query()
                            ->where('state_code', $stateCode)
                            ->where(
                                'normalized_name',
                                MunicipalityDirectory::normalize($name)
                            )
                            ->first();

                        if ($municipality === null) {
                            $missing[] = "{$unit['code']}: {$name}/{$stateCode}";

                            continue;
                        }

                        $municipality->update([
                            'region_type' => $unit['type'],
                            'region_code' => $unit['code'],
                            'region_name' => $unit['name'],
                        ]);
                    }
                }
            }
        });

        $this->directory->flush();

        return $missing;
    }

    /**
     * Busca as regiões na API de localidades do IBGE e as aplica.
     *
     * @return array{municipalities: int, metropolitan: int}
     */
    public function importFromIbgeApi(): array
    {
        $baseUrl = rtrim((string) config('geo_regions.ibge_base_url'), '/');

        $municipalities = $this->client()
            ->get($baseUrl . '/v1/localidades/municipios')
            ->throw()
            ->json();

        $regions = $this->client()
            ->get($baseUrl . '/v1/localidades/regioes-metropolitanas')
            ->throw()
            ->json();

        $regions = is_array($regions) ? $regions : [];

        foreach ($regions as $index => $region) {
            if (!isset($region['municipios']) && isset($region['id'])) {
                $detail = $this->client()
                    ->get($baseUrl . '/v1/localidades/regioes-metropolitanas/' . $region['id'])
                    ->throw()
                    ->json();

                if (is_array($detail[0] ?? null) && isset($detail[0]['municipios'])) {
                    $detail = $detail[0];
                }

                if (!is_array($detail) || !isset($detail['municipios']) || !is_array($detail['municipios'])) {
                    throw new \RuntimeException(
                        'O IBGE não retornou os municípios da região metropolitana ' . ($region['id'] ?? 'desconhecida') . '.'
                    );
                }

                $regions[$index]['municipios'] = $detail['municipios'];
            }

            if (!isset($regions[$index]['municipios']) || !is_array($regions[$index]['municipios'])) {
                throw new \RuntimeException(
                    'A lista de municípios da região metropolitana ' . ($region['id'] ?? 'desconhecida') . ' está incompleta.'
                );
            }
        }

        $municipalityCount = $this->applyMunicipalityRegions(
            is_array($municipalities) ? $municipalities : []
        );

        $metropolitanCount = $this->applyMetropolitanRegions($regions);

        // Só registra a importação como concluída depois de receber e aplicar
        // a lista completa de RMs e seus municípios. Se a API falhar, o
        // próximo deploy tentará novamente em vez de considerar a base pronta.
        DB::table('geography_import_status')->updateOrInsert(
            ['source' => 'ibge-localities'],
            [
                'records_count' => count($regions),
                'imported_at' => now(),
            ]
        );

        return [
            'municipalities' => $municipalityCount,
            'metropolitan' => $metropolitanCount,
        ];
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout(120)
            ->retry(3, 1000)
            ->acceptJson()
            ->withOptions([
                'verify' => config('geo_regions.ca_bundle') ?: true,
            ]);
    }

    /**
     * @param list<string> $supersededNames
     */
    private function clearSupersededRegions(array $supersededNames): void
    {
        if ($supersededNames === []) {
            return;
        }

        $targets = array_map(
            fn (string $name): string => MunicipalityDirectory::normalize($name),
            $supersededNames
        );

        $codes = Municipality::query()
            ->where('region_type', 'RM')
            ->whereNotNull('region_code')
            ->select('region_code', 'region_name')
            ->distinct()
            ->get()
            ->filter(fn ($row): bool => in_array(
                $this->regionBaseName((string) $row->region_name),
                $targets,
                true
            ))
            ->pluck('region_code')
            ->all();

        if ($codes !== []) {
            Municipality::query()
                ->whereIn('region_code', $codes)
                ->update([
                    'region_type' => null,
                    'region_code' => null,
                    'region_name' => null,
                ]);
        }
    }

    private function regionBaseName(string $name): string
    {
        $normalized = MunicipalityDirectory::normalize($name);

        return trim(preg_replace(
            '/^(regiao metropolitana|rm)( (de|do|da|dos|das))? /',
            '',
            $normalized
        ) ?? $normalized);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function upsert(array $rows): void
    {
        Municipality::query()->upsert(
            $rows,
            ['ibge_code'],
            ['name', 'normalized_name', 'state_code', 'latitude', 'longitude']
        );
    }
}
