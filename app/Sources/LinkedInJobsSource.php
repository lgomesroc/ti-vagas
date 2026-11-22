<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Exceptions\SourceRateLimitedException;
use App\Services\VacancyLocationFilter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class LinkedInJobsSource implements VacancySource
{
    /**
     * Código de saída do script Node para limitação temporária (EX_TEMPFAIL).
     */
    public const RATE_LIMIT_EXIT_CODE = 75;

    public function __construct(
        private readonly VacancyLocationFilter $locationFilter
    ) {
    }

    public function name(): string
    {
        return 'linkedin-jobs';
    }

    public function fetch(): iterable
    {
        $result = Process::path(base_path())
            ->env([
                'LINKEDIN_JOBS_LOOKBACK_HOURS' => (string) config('linkedin.jobs.lookback_hours', 168),
                'LINKEDIN_JOBS_MAX_PAGES' => (string) config('linkedin.jobs.max_pages', 5),
                'LINKEDIN_JOBS_DELAY_MS' => (string) config('linkedin.jobs.delay_ms', 2500),
                'LINKEDIN_JOBS_JITTER_MS' => (string) config('linkedin.jobs.jitter_ms', 500),
                'LINKEDIN_JOBS_MAX_RETRIES' => (string) config('linkedin.jobs.max_retries', 3),
                'LINKEDIN_JOBS_BACKOFF_BASE_MS' => (string) config('linkedin.jobs.backoff_base_ms', 30000),
                'LINKEDIN_JOBS_BACKOFF_MAX_MS' => (string) config('linkedin.jobs.backoff_max_ms', 300000),
                'LINKEDIN_JOBS_LOCATION' => (string) config('linkedin.jobs.location', 'Brasil'),
            ])
            ->timeout(max(600, (int) config('linkedin.jobs.timeout', 1800)))
            ->run(['node', 'scripts/linkedin/jobs.mjs']);

        if ($result->exitCode() === self::RATE_LIMIT_EXIT_CODE) {
            $retryAfter = self::parseRetryAfter($result->errorOutput());

            Log::warning('LinkedIn Jobs limitou as requisições (HTTP 429); coleta interrompida.', [
                'source' => $this->name(),
                'retry_after_seconds' => $retryAfter,
                'detail' => trim($result->errorOutput()),
            ]);

            throw new SourceRateLimitedException(
                'LinkedIn Jobs limitou as requisições (HTTP 429); a coleta foi interrompida e deve ser reagendada.',
                $retryAfter,
                $this->name()
            );
        }

        if (!$result->successful()) {
            throw new RuntimeException(
                'Falha na coleta pública de LinkedIn Jobs: '
                . trim($result->errorOutput() ?: $result->output())
            );
        }

        $vacancies = json_decode($result->output(), true);

        if (!is_array($vacancies)) {
            throw new RuntimeException('LinkedIn Jobs retornou JSON inválido.');
        }

        return $this->filterFetched($vacancies);
    }

    /**
     * Extrai o Retry-After (segundos) informado pelo script, quando houver.
     */
    public static function parseRetryAfter(string $errorOutput): ?int
    {
        if (preg_match('/LINKEDIN_RATE_LIMITED\s+retry_after=(\d+)/', $errorOutput, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    public function filterFetched(array $vacancies): array
    {
        // A captura pública é ampla. Os filtros editoriais, geográficos e
        // de senioridade permanecem centralizados no VacancyImporter.
        return array_values(array_filter($vacancies, function (array $vacancy): bool {
            $title = trim((string) ($vacancy['title'] ?? ''));
            $company = trim((string) ($vacancy['company'] ?? ''));
            $description = trim((string) ($vacancy['description'] ?? ''));
            $applicationUrl = trim((string) ($vacancy['application_url'] ?? ''));
            $publishedAt = trim((string) ($vacancy['original_published_at'] ?? ''));

            return $title !== ''
                && $company !== ''
                && mb_strlen($description) >= 30
                && filter_var($applicationUrl, FILTER_VALIDATE_URL) !== false
                && $publishedAt !== ''
                && trim((string) ($vacancy['external_id'] ?? '')) !== '';
        }));
    }
}
