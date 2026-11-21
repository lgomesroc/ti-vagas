<?php

namespace App\Console\Commands;

use App\Services\VacancyImporter;
use App\Services\VacancySourceFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class CollectSingleVacancySource extends Command
{
    protected $signature = 'vacancies:collect-source {source : Identificador da fonte configurada}';

    protected $description = 'Coleta e importa vagas de uma única fonte configurada';

    public function handle(
        VacancySourceFactory $factory,
        VacancyImporter $importer
    ): int {
        $sourceName = (string) $this->argument('source');

        try {
            $source = $factory->make($sourceName);
            $this->info('Fonte: ' . $source->name());
            $this->info('Banco configurado: ' . config('database.default'));

            $vacancies = $source->fetch();
            $received = is_countable($vacancies) ? count($vacancies) : iterator_count($vacancies);

            $this->info("Vagas retornadas pela fonte: {$received}");

            $result = $importer->importFetched($source->name(), $vacancies);

            $this->table(
                ['Recebidas', 'Novas', 'Atualizadas', 'Ignoradas'],
                [[
                    $received,
                    $result['created'],
                    $result['updated'],
                    $result['skipped'],
                ]]
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('Falha na coleta de uma fonte específica de vagas.', [
                'source' => $sourceName,
                'error' => $exception->getMessage(),
            ]);

            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
