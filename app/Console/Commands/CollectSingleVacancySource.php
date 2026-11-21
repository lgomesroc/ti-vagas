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
            if ($vacancies instanceof \Traversable) {
                $vacancies = iterator_to_array($vacancies, false);
            }
            $received = count($vacancies);

            if (method_exists($source, 'diagnostics')) {
                $diagnostics = $source->diagnostics();
                if ($diagnostics !== []) {
                    $this->newLine();
                    $this->info('Diagnóstico da coleta:');
                    $this->table(
                        ['Etapa', 'Quantidade'],
                        array_map(
                            static fn (string $label, int $count): array => [$label, $count],
                            array_keys($diagnostics),
                            array_values($diagnostics)
                        )
                    );
                }
            }

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
