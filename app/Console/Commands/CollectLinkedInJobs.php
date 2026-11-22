<?php

namespace App\Console\Commands;

use App\Exceptions\SourceRateLimitedException;
use App\Jobs\FetchLinkedInJobsJob;
use App\Services\VacancyImporter;
use App\Services\VacancyLocationFilter;
use App\Sources\LinkedInJobsSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class CollectLinkedInJobs extends Command
{
    protected $signature = 'linkedin:collect-jobs {--queue : Envia a coleta para a fila, com reagendamento automático em caso de HTTP 429}';

    protected $description = 'Coleta vagas do LinkedIn Jobs usando a sessão autenticada da conta secundária';

    public function handle(
        VacancyImporter $importer,
        VacancyLocationFilter $locationFilter
    ): int {
        if (!config('linkedin.secondary_account.enabled')) {
            $this->warn('A coleta autenticada do LinkedIn está desabilitada.');
            return self::SUCCESS;
        }

        if ($this->option('queue')) {
            FetchLinkedInJobsJob::dispatch();
            $this->info('Job de coleta do LinkedIn Jobs enviado para a fila "vacancies".');

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
        } catch (SourceRateLimitedException $exception) {
            // Limitação temporária: não é erro da aplicação; tentar na próxima execução.
            $this->warn($exception->getMessage());

            if ($exception->retryAfterSeconds !== null) {
                $this->warn('Retry-After informado: ' . $exception->retryAfterSeconds . 's.');
            }

            $this->warn('Nenhuma vaga foi importada nesta execução. Tente novamente mais tarde ou use --queue.');

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
