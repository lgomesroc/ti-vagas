<?php

namespace App\Services\Geo;

use App\Models\Municipality;
use Illuminate\Support\Facades\DB;
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
     * Importa a base oficial DTB 2025 já normalizada em CSV.
     * As coordenadas existentes são preservadas pelo código IBGE.
     */
    public function importFromDtbCsv(?string $csvPath = null): array
    {
        $csvPath ??= database_path('data/ibge/municipios_dtb_2025.csv');
        $handle = is_readable($csvPath) ? fopen($csvPath, 'r') : false;

        if ($handle === false) {
            throw new InvalidArgumentException("Arquivo DTB 2025 não encontrado: {$csvPath}");
        }

        $header = fgetcsv($handle, 0, ',', '"', '');
        if ($header === false) {
            fclose($handle);
            throw new InvalidArgumentException('O CSV da DTB 2025 está vazio.');
        }

        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
        $columns = array_flip($header);
        $required = [
            'codigo_ibge', 'nome', 'codigo_uf',
            'intermediate_region_code', 'intermediate_region_name',
            'immediate_region_code', 'immediate_region_name',
        ];

        foreach ($required as $column) {
            if (!array_key_exists($column, $columns)) {
                fclose($handle);
                throw new InvalidArgumentException("Coluna obrigatória ausente no CSV DTB 2025: {$column}");
            }
        }

        $existingCoordinates = Municipality::query()
            ->whereNotNull('latitude')
            ->orWhereNotNull('longitude')
            ->get(['ibge_code', 'latitude', 'longitude'])
            ->keyBy('ibge_code');

        $rows = [];
        $seen = [];
        $count = 0;
        $batch = [];

        while (($line = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if (count($line) < count($header)) {
                fclose($handle);
                throw new InvalidArgumentException('Linha incompleta encontrada no CSV DTB 2025.');
            }

            $code = str_pad(trim((string) $line[$columns['codigo_ibge']]), 7, '0', STR_PAD_LEFT);
            $state = self::STATE_BY_IBGE_PREFIX[substr($code, 0, 2)] ?? null;
            $name = trim((string) $line[$columns['nome']]);

            if (!preg_match('/^\d{7}$/', $code) || $state === null || $name === '') {
                fclose($handle);
                throw new InvalidArgumentException("Município inválido no CSV DTB 2025: {$code}.");
            }

            if (isset($seen[$code])) {
                fclose($handle);
                throw new InvalidArgumentException("Código de município duplicado na DTB 2025: {$code}.");
            }
            $seen[$code] = true;

            $immediateCode = trim((string) $line[$columns['immediate_region_code']]);
            $immediateName = trim((string) $line[$columns['immediate_region_name']]);
            $intermediateCode = trim((string) $line[$columns['intermediate_region_code']]);
            $intermediateName = trim((string) $line[$columns['intermediate_region_name']]);

            if ($immediateCode === '' || $immediateName === '' || $intermediateCode === '' || $intermediateName === '') {
                fclose($handle);
                throw new InvalidArgumentException("Região imediata/intermediária ausente para {$code} - {$name}.");
            }

            $coordinates = $existingCoordinates->get($code);
            $batch[] = [
                'ibge_code' => $code,
                'name' => $name,
                'normalized_name' => MunicipalityDirectory::normalize($name),
                'state_code' => $state,
                'latitude' => $coordinates?->latitude,
                'longitude' => $coordinates?->longitude,
                'immediate_region_code' => $immediateCode,
                'immediate_region_name' => $immediateName,
                'intermediate_region_code' => $intermediateCode,
                'intermediate_region_name' => $intermediateName,
            ];

            if (count($batch) >= 500) {
                $rows[] = $batch;
                $batch = [];
            }
            $count++;
        }

        fclose($handle);

        if ($batch !== []) {
            $rows[] = $batch;
        }

        if ($count < 5000) {
            throw new InvalidArgumentException("Quantidade inesperada de municípios na DTB 2025: {$count}.");
        }

        DB::transaction(function () use ($rows): void {
            foreach ($rows as $chunk) {
                Municipality::query()->upsert(
                    $chunk,
                    ['ibge_code'],
                    [
                        'name', 'normalized_name', 'state_code', 'latitude', 'longitude',
                        'immediate_region_code', 'immediate_region_name',
                        'intermediate_region_code', 'intermediate_region_name',
                    ]
                );
            }

            DB::table('geography_import_status')->updateOrInsert(
                ['source' => 'ibge-dtb-2025'],
                ['records_count' => array_sum(array_map('count', $rows)), 'imported_at' => now()]
            );
        });

        $this->directory->flush();

        return [
            'municipalities' => $count,
            'immediate_regions' => Municipality::query()->whereNotNull('immediate_region_code')->distinct()->count('immediate_region_code'),
            'intermediate_regions' => Municipality::query()->whereNotNull('intermediate_region_code')->distinct()->count('intermediate_region_code'),
        ];
    }

    /**
     * Aplica regiões a partir de uma estrutura normalizada, útil para fixtures.
     *
     * @param iterable<array<string, mixed>> $payload
     */
    public function applyMunicipalityRegions(iterable $payload): int
    {
        $updated = 0;

        DB::transaction(function () use ($payload, &$updated): void {
            foreach ($payload as $item) {
                $immediate = $item['regiao-imediata'] ?? null;
                $intermediate = is_array($immediate)
                    ? ($immediate['regiao-intermediaria'] ?? [])
                    : [];

                if (!isset($item['id'], $immediate['id'], $immediate['nome'], $intermediate['id'], $intermediate['nome'])) {
                    continue;
                }

                $updated += Municipality::query()
                    ->where('ibge_code', str_pad((string) $item['id'], 7, '0', STR_PAD_LEFT))
                    ->update([
                        'immediate_region_code' => (string) $immediate['id'],
                        'immediate_region_name' => (string) $immediate['nome'],
                        'intermediate_region_code' => (string) $intermediate['id'],
                        'intermediate_region_name' => (string) $intermediate['nome'],
                    ]);
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
