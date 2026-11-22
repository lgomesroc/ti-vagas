<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Services\VacancyLocationFilter;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class LinkedInJobsSource implements VacancySource
{
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
                'LINKEDIN_JOBS_DELAY_MS' => (string) config('linkedin.jobs.delay_ms', 1200),
                'LINKEDIN_JOBS_LOCATION' => (string) config('linkedin.jobs.location', 'Brasil'),
            ])
            ->timeout(max(600, (int) config('linkedin.jobs.timeout', 1800)))
            ->run(['node', 'scripts/linkedin/jobs.mjs']);

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
