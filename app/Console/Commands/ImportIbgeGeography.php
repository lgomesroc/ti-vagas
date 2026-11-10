<?php

namespace App\Console\Commands;

use Illuminate\Support\Facades\DB;
use App\Services\Geo\IbgeGeographyImporter;
use Illuminate\Console\Command;
use Throwable;

class ImportIbgeGeography extends Command
{
    protected $signature = 'geo:import-ibge
        {--offline : Não consulta a API do IBGE (só municípios e ajustes locais)}
        {--csv= : Caminho de um CSV de municípios alternativo}
        {--if-empty : Só executa se as regiões do IBGE ainda não foram importadas}';

    protected $description = 'Importa municípios, regiões intermediárias, RMs e RIDEs do IBGE';

    public function handle(IbgeGeographyImporter $importer): int
    {
        if (
            $this->option('if-empty')
            && DB::table('geography_import_status')
                ->where('source', 'ibge-localities')
                ->whereNotNull('imported_at')
                ->exists()
        ) {
            $this->info('Base territorial já importada. Nada a fazer.');

            return self::SUCCESS;
        }

        $total = $importer->importMunicipalities(
            $this->option('csv') ?: null
        );

        $this->info("Municípios importados: {$total}");

        if (!$this->option('offline')) {
            try {
                $result = $importer->importFromIbgeApi();

                $this->info(
                    "Regiões do IBGE aplicadas: {$result['municipalities']} municípios, "
                    . "{$result['metropolitan']} em RMs"
                );
            } catch (Throwable $exception) {
                $this->error(
                    'Não foi possível consultar a API do IBGE: '
                    . $exception->getMessage()
                );
                $this->warn(
                    'Sem as regiões intermediárias, só RMs e RIDEs dos ajustes '
                    . 'locais ficam disponíveis. Execute o comando novamente.'
                );
            }
        }

        $missing = $importer->applyCuratedUnits();

        $this->info('Ajustes de RMs e RIDEs aplicados (config/geo_regions.php).');

        foreach ($missing as $member) {
            $this->warn("Município não encontrado: {$member}");
        }

        return self::SUCCESS;
    }
}
