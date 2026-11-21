<?php

namespace App\Console\Commands;

use App\Services\VacancyImporter;
use App\Services\VacancyLocationFilter;
use App\Sources\LinkedInJobsSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class CollectLinkedInJobs extends Command
{
    protected $signature = 'linkedin:collect-jobs';

    protected $description = 'Coleta vagas do LinkedIn Jobs usando a sessão autenticada da conta secundária';

    public function handle(
        VacancyImporter $importer,
        VacancyLocationFilter $locationFilter
    ): int {
        if (!config('linkedin.secondary_account.enabled')) {
            $this->warn('A coleta autenticada do LinkedIn está desabilitada.');
            return self::SUCCESS;
        }

        $this->info('Banco configurado: ' . config('database.default'));

        try {
            $source = new LinkedInJobsSource($locationFilter);
            $vacancies = $source->fetch();
            $this->info('Vagas aprovadas pelos filtros da fonte: ' . count($vacancies));
            $result = $importer->importFetched($source->name(), $vacancies);

            $this->table(
                ['Recebidas elegíveis', 'Novas', 'Atualizadas', 'Ignoradas'],
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
