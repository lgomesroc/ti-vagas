<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Exceptions\SourceRateLimitedException;
use App\Models\LinkedInJobScan;
use App\Models\Vacancy;
use App\Services\VacancyLocationFilter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class LinkedInJobsSource implements VacancySource
{
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
        $knownIds = LinkedInJobScan::query()
            ->pluck('job_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        // Compatibilidade com vagas importadas antes do histórico de consultas.
        $existingIds = Vacancy::query()
            ->where('external_id', 'like', 'linkedin-jobs:%')
            ->pluck('external_id')
            ->map(fn ($externalId): string => substr((string) $externalId, strlen('linkedin-jobs:')))
            ->all();

        $knownIds = array_values(array_unique(array_merge($knownIds, $existingIds)));
        $knownIdsFile = tempnam(sys_get_temp_dir(), 'ti-vagas-linkedin-known-');
        $scanLogFile = tempnam(sys_get_temp_dir(), 'ti-vagas-linkedin-scans-');

        if ($knownIdsFile === false || $scanLogFile === false) {
            if (is_string($knownIdsFile)) @unlink($knownIdsFile);
            if (is_string($scanLogFile)) @unlink($scanLogFile);
            throw new RuntimeException('Não foi possível preparar os arquivos temporários da coleta LinkedIn Jobs.');
        }

        file_put_contents($knownIdsFile, json_encode($knownIds, JSON_THROW_ON_ERROR));

        try {
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
                    'LINKEDIN_JOBS_KNOWN_IDS_FILE' => $knownIdsFile,
                    'LINKEDIN_JOBS_SCAN_LOG_FILE' => $scanLogFile,
                ])
                ->timeout(max(600, (int) config('linkedin.jobs.timeout', 1800)))
                ->run(['node', 'scripts/linkedin/jobs.mjs']);
        } finally {
            // O Node registra cada resposta de detalhe; persistimos também em caso de 429.
            $this->persistScanLog($scanLogFile);
            @unlink($knownIdsFile);
            @unlink($scanLogFile);
        }

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
     * Registra qualquer resposta HTTP da consulta detalhada. HTTP 4xx/5xx
     * também contam como consultados; HTTP 429 e falhas sem resposta não.
     */
    private function persistScanLog(string $path): void
    {
        if (!is_file($path)) return;

        $rows = [];
        $now = now();

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $entry = json_decode($line, true);
            $jobId = is_array($entry) ? (string) ($entry['jobId'] ?? '') : '';
            if ($jobId === '' || !ctype_digit($jobId)) continue;

            $status = $entry['httpStatus'] ?? null;
            $rows[] = [
                'job_id' => $jobId,
                'http_status' => is_numeric($status) ? (int) $status : null,
                'consulted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('linkedin_job_scans')->insertOrIgnore($rows);
        }
    }

    public static function parseRetryAfter(string $errorOutput): ?int
    {
        if (preg_match('/LINKEDIN_RATE_LIMITED\s+retry_after=(\d+)/', $errorOutput, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    public function filterFetched(array $vacancies): array
    {
        // Os filtros editoriais, geográficos e de senioridade permanecem centralizados no importador.
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
