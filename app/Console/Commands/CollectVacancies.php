<?php

namespace App\Console\Commands;

use App\Jobs\FetchVacancySourceJob;
use App\Services\VacancyImporter;
use App\Services\VacancySourceFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;

class CollectVacancies extends Command
{
    protected $signature = 'vacancies:collect {--sync : Executa a coleta diretamente, sem fila}';

    protected $description = 'Coleta vagas das fontes configuradas';

    public function handle(
        VacancySourceFactory $factory,
        VacancyImporter $importer
    ): int {
        if ($this->option('sync')) {
            return $this->collectSynchronously($factory, $importer);
        }

        return $this->dispatchToQueue($factory);
    }

    private function dispatchToQueue(
        VacancySourceFactory $factory
    ): int {
        $sources = $factory->all();

        if (empty($sources)) {
            $this->warn('Nenhuma fonte configurada.');

            return self::SUCCESS;
        }

        $this->info('Fontes configuradas: ' . count($sources));
        $this->info('Fila: vacancies');
        $this->info('Conexão: database');

        $dispatched = 0;

        foreach (array_keys($sources) as $sourceName) {
            FetchVacancySourceJob::dispatch($sourceName);

            $dispatched++;

            $this->line("Job enviado: {$sourceName}");
        }

        $queueSize = Queue::connection('database')->size('vacancies');

        $this->newLine();
        $this->info('DESPACHO CONCLUÍDO');
        $this->line("Jobs enviados: {$dispatched}");
        $this->line("Jobs aguardando na fila: {$queueSize}");

        return self::SUCCESS;
    }

    private function collectSynchronously(
        VacancySourceFactory $factory,
        VacancyImporter $importer
    ): int {
        $sources = $factory->all();

        if (empty($sources)) {
            $this->warn('Nenhuma fonte configurada.');

            return self::SUCCESS;
        }

        $totals = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        $this->info('Iniciando coleta síncrona.');
        $this->info('Fontes configuradas: ' . count($sources));
        $this->newLine();

        foreach ($sources as $sourceName => $source) {
            $startedAt = microtime(true);

            $this->line("Consultando fonte: {$sourceName}");

            try {
                $vacancies = $source->fetch();

                $result = $importer->importFetched(
                    $source->name(),
                    $vacancies
                );

                foreach (['created', 'updated', 'skipped'] as $key) {
                    $totals[$key] += $result[$key] ?? 0;
                }

                $duration = round(microtime(true) - $startedAt, 2);

                $this->info(
                    "Concluída: {$sourceName} | "
                    . "Novas: {$result['created']} | "
                    . "Atualizadas: {$result['updated']} | "
                    . "Ignoradas: {$result['skipped']} | "
                    . "Tempo: {$duration}s"
                );
            } catch (Throwable $exception) {
                $totals['failed']++;

                Log::error('Falha na coleta síncrona de vagas.', [
                    'source' => $sourceName,
                    'error' => $exception->getMessage(),
                ]);

                $this->error(
                    "Falha: {$sourceName} | {$exception->getMessage()}"
                );
            }

            $this->newLine();
        }

        $this->info('RESUMO DA COLETA');
        $this->line("Novas vagas: {$totals['created']}");
        $this->line("Atualizadas: {$totals['updated']}");
        $this->line("Ignoradas: {$totals['skipped']}");
        $this->line("Fontes com falha: {$totals['failed']}");

        return $totals['failed'] > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
