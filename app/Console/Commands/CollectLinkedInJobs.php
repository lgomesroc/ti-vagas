<?php

namespace App\Console\Commands;

use App\Jobs\FetchLinkedInJobsJob;
use App\Services\VacancyImporter;
use App\Services\VacancyLocationFilter;
use App\Sources\LinkedInJobsSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class CollectLinkedInJobs extends Command
{
    protected $signature = 'linkedin:collect-jobs {--sync : Executa a coleta imediatamente, sem fila}';

    protected $description = 'Enfileira ou executa a coleta pública de vagas do LinkedIn Jobs';

    public function handle(
        VacancyImporter $importer,
        VacancyLocationFilter $locationFilter
    ): int {
        if (!config('linkedin.jobs.enabled')) {
            $this->warn('A coleta pública de LinkedIn Jobs está desabilitada.');
            return self::SUCCESS;
        }

        if (!$this->option('sync')) {
            FetchLinkedInJobsJob::dispatch();

            $this->info('Coleta do LinkedIn Jobs enviada para a fila linkedin.');
            $this->line('Execute um worker da fila linkedin para processá-la.');

            return self::SUCCESS;
        }

        $this->info('Banco configurado: ' . config('database.default'));

        try {
            $source = new LinkedInJobsSource($locationFilter);
            $vacancies = $source->fetch();
            $this->info('Vagas extraídas para importação: ' . count($vacancies));
            $result = $importer->importFetched($source->name(), $vacancies);

            $this->table(
                ['Extraídas', 'Novas', 'Atualizadas', 'Ignoradas'],
                [[count($vacancies), $result['created'], $result['updated'], $result['skipped']]]
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('Falha na coleta de LinkedIn Jobs.', [
                'error' => $exception->getMessage(),
            ]);
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
