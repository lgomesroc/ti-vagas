<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Services\VacancyLocationFilter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class InhireSource implements VacancySource
{
    private const BASE_URL = 'https://api.inhire.app';

    public function __construct(
        private readonly string $tenant,
        private readonly string $companyName,
        private readonly ?string $caBundle = null,
        private readonly int $maxJobs = 200,
        private readonly ?VacancyLocationFilter $locationFilter = null,
    ) {
    }

    public function name(): string
    {
        return 'inhire-' . $this->tenant;
    }

    public function fetch(): iterable
    {
        $response = $this->http()
            ->get(self::BASE_URL . '/job-posts/public/pages');

        $response->throw();

        $jobs = $response->json('jobsPage', []);

        if (!is_array($jobs)) {
            throw new \RuntimeException(
                'A InHire retornou uma lista de vagas inválida.'
            );
        }

        $vacancies = [];
        $processed = 0;

        foreach ($jobs as $job) {
            if (
                !is_array($job)
                || ($job['status'] ?? null) !== 'published'
                || empty($job['jobId'])
            ) {
                continue;
            }

            if ($processed >= $this->maxJobs) {
                break;
            }

            $processed++;

            try {
                $vacancy = $this->fetchJobDetails($job);

                if ($vacancy !== null) {
                    $vacancies[] = $vacancy;
                }
            } catch (Throwable $exception) {
                Log::warning('Falha ao consultar vaga da InHire.', [
                    'tenant' => $this->tenant,
                    'job_id' => $job['jobId'],
                    'error' => $exception->getMessage(),
                ]);

                continue;
            }
        }

        return $vacancies;
    }

    private function fetchJobDetails(array $job): ?array
    {
        $jobId = (string) $job['jobId'];

        $response = $this->http()
            ->get(
                self::BASE_URL . '/job-posts/public/pages/' . $jobId
            );

        $response->throw();

        $details = $response->json();

        if (!is_array($details)) {
            return null;
        }

        $title = trim(
            (string) (
                $details['displayName']
                ?? $job['displayName']
                ?? ''
            )
        );

        if ($title === '') {
            return null;
        }

        $description = $this->cleanDescription(
            (string) ($details['description'] ?? '')
        );

        if (!$this->isTechnologyVacancy($title, $description)) {
            return null;
        }

        $location = trim(
            (string) (
                $details['location']
                ?? $job['location']
                ?? ''
            )
        );

        $location = preg_replace(
            '/,\s*BR$/i',
            ', Brasil',
            $location
        ) ?? $location;

        $workplaceType = $details['workplaceType']
            ?? $job['workplaceType']
            ?? null;

        $workModel = $this->normalizeWorkModel($workplaceType);

        if (
            $this->locationFilter !== null
            && !$this->locationFilter->isEligible(
                $location,
                $title,
                $description,
                $workModel
            )
        ) {
            return null;
        }

        $publishedAt = $details['publishedAt'] ?? null;

        if (empty($publishedAt)) {
            return null;
        }

        try {
            $originalPublishedAt = CarbonImmutable::parse($publishedAt);
        } catch (Throwable $exception) {
            Log::warning('Data de publicação inválida na InHire.', [
                'tenant' => $this->tenant,
                'job_id' => $jobId,
                'published_at' => $publishedAt,
            ]);

            return null;
        }

        $applicationUrl = $this->extractApplicationUrl($details);

        if ($applicationUrl === null) {
            $applicationUrl = sprintf(
                'https://%s.inhire.app/vagas/%s/%s',
                $this->tenant,
                $jobId,
                Str::slug($title)
            );
        }

        return [
            'title' => $title,
            'company' => $this->companyName,
            'description' => $description,
            'location' => $location,
            'work_model' => $workModel,
            'employment_type' => $this->extractEmploymentType($details),
            'salary' => $this->extractSalary($details),
            'application_url' => $applicationUrl,
            'external_id' => $jobId,
            'original_published_at' => $originalPublishedAt,
        ];
    }

    private function http()
    {
        return Http::acceptJson()
            ->withHeaders([
                'X-Tenant' => $this->tenant,
            ])
            ->timeout(30)
            ->withOptions([
                'verify' => $this->caBundle ?: true,
            ]);
    }

    private function normalizeWorkModel(?string $workplaceType): ?string
    {
        return match (Str::lower(trim((string) $workplaceType))) {
            'remote' => 'Remoto',
            'hybrid' => 'Híbrido',
            'on-site', 'onsite' => 'Presencial',
            default => null,
        };
    }

    private function cleanDescription(string $description): string
    {
        $description = html_entity_decode(
            $description,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $description = strip_tags($description);

        return trim(
            preg_replace('/\s+/u', ' ', $description) ?? ''
        );
    }

    private function isTechnologyVacancy(
        string $title,
        string $description
    ): bool {
        $text = Str::ascii(
            mb_strtolower($title . ' ' . $description)
        );

        return preg_match(
            '/desenvolvedor|developer|programador|programmer|software|backend|back-end|frontend|front-end|full.?stack|analista de sistemas|suporte ti|suporte tecnico|infraestrutura|infra|redes|cloud|devops|banco de dados|database|qa|quality assurance|testes de software|seguranca da informacao|cyber security|help.?desk|service desk|sistemas/',
            $text
        ) === 1;
    }

    private function extractApplicationUrl(array $details): ?string
    {
        $boardsData = $details['jobBoardsData'] ?? [];

        if (!is_array($boardsData)) {
            return null;
        }

        foreach ($boardsData as $board) {
            if (!is_array($board)) {
                continue;
            }

            foreach ($board as $value) {
                if (
                    is_array($value)
                    && isset($value['jobUrl'])
                    && filter_var(
                        $value['jobUrl'],
                        FILTER_VALIDATE_URL
                    )
                ) {
                    return $value['jobUrl'];
                }
            }
        }

        return null;
    }

    private function extractEmploymentType(array $details): ?string
    {
        $contractType = $details['contractType'] ?? null;

        if (is_array($contractType)) {
            $values = array_filter(
                $contractType,
                fn ($value) => is_string($value) && trim($value) !== ''
            );

            return $values !== [] ? implode(', ', $values) : null;
        }

        return is_string($contractType) && trim($contractType) !== ''
            ? trim($contractType)
            : null;
    }

    private function extractSalary(array $details): ?string
    {
        foreach (
            ['salary', 'salaryRange', 'salaryDescription']
            as $field
        ) {
            $value = $details[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }

            if (is_numeric($value)) {
                return (string) $value;
            }
        }

        return null;
    }
}
