<?php

namespace App\Console\Commands;

use App\Services\Geo\IbgeGeographyImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ImportIbgeGeography extends Command
{
    protected $signature = 'geo:import-ibge
        {--csv= : Caminho do CSV normalizado da DTB 2025}
        {--if-empty : Só executa se a DTB 2025 ainda não foi importada}';

    protected $description = 'Importa municípios e regiões geográficas da DTB 2025 do IBGE';

    public function handle(IbgeGeographyImporter $importer): int
    {
        if (
            $this->option('if-empty')
            && DB::table('geography_import_status')
                ->where('source', 'ibge-dtb-2025')
                ->whereNotNull('imported_at')
                ->exists()
        ) {
            $this->info('DTB 2025 já importada. Nada a fazer.');

            return self::SUCCESS;
        }

        try {
            // O CSV legado fornece os centroides; a DTB 2025 atualiza os
            // municípios e suas regiões oficiais pelo código IBGE.
            $coordinates = $importer->importMunicipalities();
            $result = $importer->importFromDtbOds();
        } catch (Throwable $exception) {
            $this->error('Falha ao importar a DTB 2025: ' . $exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Centroides municipais carregados: {$coordinates}");
        $this->info("Municípios da DTB 2025 importados: {$result['municipalities']}");
        $this->info("Regiões Geográficas Imediatas: {$result['immediate_regions']}");
        $this->info("Regiões Geográficas Intermediárias: {$result['intermediate_regions']}");

        $missing = $importer->applyCuratedUnits();
        $this->info('Ajustes de RMs e RIDEs aplicados (config/geo_regions.php).');

        foreach ($missing as $member) {
            $this->warn("Município não encontrado: {$member}");
        }

        return self::SUCCESS;
    }
}
