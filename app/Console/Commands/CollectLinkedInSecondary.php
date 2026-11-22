<?php

namespace App\Console\Commands;

use App\Services\LinkedInPostAutoPublisher;
use App\Services\LinkedInPostCandidateProcessor;
use App\Services\LinkedInPublicPostFetcher;
use App\Services\VacancyImporter;
use App\Services\VacancyLocationFilter;
use App\Sources\LinkedInJobsSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Throwable;

class CollectLinkedInSecondary extends Command
{
    protected $signature = 'linkedin:collect-secondary';

    protected $description = 'Coleta publicações e vagas do LinkedIn na mesma sessão autenticada';

    public function handle(
        LinkedInPublicPostFetcher $fetcher,
        LinkedInPostCandidateProcessor $processor,
        LinkedInPostAutoPublisher $publisher,
        VacancyImporter $importer,
        VacancyLocationFilter $locationFilter
    ): int {
        if (!config('linkedin.enabled') || !config('linkedin.secondary_account.enabled')) {
            $this->warn('A coleta da conta secundária do LinkedIn está desabilitada.');
            return self::SUCCESS;
        }

        $state = (string) config('linkedin.secondary_account.storage_state');
        $statePath = str_starts_with($state, DIRECTORY_SEPARATOR) ? $state : base_path($state);
        if (!is_file($statePath)) {
            $this->error('Estado de sessão do LinkedIn não encontrado: ' . $statePath);
            return self::FAILURE;
        }

        try {
            $result = Process::path(base_path())
                ->env([
                    'LINKEDIN_SECONDARY_ACCOUNT_PROFILE' => (string) config('linkedin.secondary_account.profile'),
                    'LINKEDIN_STORAGE_STATE_PATH' => (string) config('linkedin.secondary_account.storage_state'),
                    'LINKEDIN_HEADLESS' => (string) env('LINKEDIN_HEADLESS', 'false'),
                    'LINKEDIN_POSTS_LOOKBACK_HOURS' => (string) config('linkedin.lookback_hours', 72),
                ] + LinkedInJobsSource::rateLimitEnvironment())
                ->timeout(max(600, (int) config('linkedin.secondary_account.timeout', 1800)))
                ->run(['node', 'scripts/linkedin/secondary-account.mjs', 'all']);

            if (!$result->successful()) {
                throw new \RuntimeException('Falha no navegador autenticado: ' . trim($result->errorOutput()));
            }

            $payload = json_decode($result->output(), true);
            if (!is_array($payload) || !is_array($payload['posts'] ?? null) || !is_array($payload['vacancies'] ?? null)) {
                throw new \RuntimeException('O coletor do LinkedIn retornou JSON inválido.');
            }

            if (is_array($payload['vacancies_rate_limited'] ?? null)) {
                Log::warning('Coleta de vagas do LinkedIn adiada por limitação (HTTP 429); publicações foram preservadas.', [
                    'reason' => $payload['vacancies_rate_limited']['reason'] ?? null,
                    'retry_after_seconds' => $payload['vacancies_rate_limited']['retry_after_seconds'] ?? null,
                ]);
                $this->warn('Vagas do LinkedIn adiadas por limitação (HTTP 429); as publicações foram processadas normalmente.');
            }

            $postTotals = ['received' => count($payload['posts']), 'created' => 0, 'updated' => 0, 'rejected' => 0, 'duplicates' => 0, 'failed' => 0];
            foreach ($payload['posts'] as $post) {
                try {
                    $post = $fetcher->fetch($post);
                    $processed = $processor->process($post);
                    foreach (['created', 'updated', 'rejected', 'duplicates'] as $key) {
                        $postTotals[$key] += $processed[$key] ?? 0;
                    }
                } catch (Throwable $exception) {
                    $postTotals['failed']++;
                    Log::warning('Falha ao processar publicação da conta secundária.', [
                        'post_url' => $post['postUrl'] ?? null,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            $publication = $publisher->publishPending();
            $postTotals['failed'] += $publication['failed'];

            // Aplica novamente os critérios de área, senioridade e localização
            // antes de entregar os registros ao importador central.
            $source = new LinkedInJobsSource($locationFilter);
            $eligibleVacancies = $source->filterFetched($payload['vacancies']);
            $vacancyTotals = $importer->importFetched($source->name(), $eligibleVacancies);

            $this->table(
                ['Publicações', 'Novas publicações', 'Vagas recebidas', 'Vagas novas', 'Vagas atualizadas', 'Vagas ignoradas', 'Falhas'],
                [[
                    $postTotals['received'],
                    $postTotals['created'],
                    count($eligibleVacancies),
                    $vacancyTotals['created'],
                    $vacancyTotals['updated'],
                    $vacancyTotals['skipped'],
                    $postTotals['failed'],
                ]]
            );

            return $postTotals['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('Falha na coleta conjunta da conta secundária do LinkedIn.', [
                'error' => $exception->getMessage(),
            ]);
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
