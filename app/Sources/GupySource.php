<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
use DOMDocument;

class GupySource implements VacancySource
{
    public function __construct(
        private readonly string $subdomain,
        private readonly string $companyName,
        private readonly ?string $caBundle = null,
        private readonly int $maxJobs = 200,
        private readonly ?string $baseUrl = null,
    ) {
    }

    public function name(): string
    {
        return 'gupy-' . $this->subdomain;
    }

    public function fetch(): iterable
    {
        $baseUrl = rtrim(
            $this->baseUrl ?: "https://{$this->subdomain}.gupy.io",
            '/'
        );

        try {
            $response = $this->http()->get($baseUrl . '/');

            if (!$response->successful()) {
                Log::warning('Gupy: falha ao consultar página de vagas.', [
                    'company' => $this->companyName,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $jobIds = $this->extractJobIds($response->body());
            $jobs = [];

            foreach ($jobIds as $jobId) {
                if (count($jobs) >= $this->maxJobs) {
                    break;
                }

                $job = $this->fetchJob(
                    baseUrl: $baseUrl,
                    jobId: $jobId,
                );

                if ($job !== null) {
                    $jobs[] = $job;
                }
            }

            Log::info('Gupy: coleta concluída.', [
                'company' => $this->companyName,
                'jobs_found' => count($jobIds),
                'jobs_extracted' => count($jobs),
            ]);

            return $jobs;
        } catch (Throwable $exception) {
            Log::error('Gupy: erro ao coletar vagas.', [
                'company' => $this->companyName,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    private function extractJobIds(string $html): array
    {
        $dom = new DOMDocument();

        @$dom->loadHTML($html);

        $node = $dom->getElementById('__NEXT_DATA__');

        if ($node !== null) {
            $data = json_decode($node->textContent, true);

            $jobs = $data['props']['pageProps']['jobs'] ?? [];

            if (is_array($jobs)) {
                $ids = [];

                foreach ($jobs as $job) {
                    $id = $job['id'] ?? null;

                    if ($id !== null && is_scalar($id)) {
                        $ids[] = (string) $id;
                    }
                }

                if ($ids !== []) {
                    return array_values(array_unique($ids));
                }
            }
        }

        // Fallback para páginas sem __NEXT_DATA__.
        $ids = [];

        foreach ($dom->getElementsByTagName('a') as $anchor) {
            $href = $anchor->getAttribute('href');

            if (preg_match('~/jobs/(\d+)~', $href, $matches)) {
                $ids[] = $matches[1];
            }
        }

        return array_values(array_unique($ids));
    }

    private function fetchJob(string $baseUrl, string $jobId): ?array
    {
        try {
            $url = $baseUrl . '/jobs/' . $jobId;

            $response = $this->http()->get($url);

            if (!$response->successful()) {
                return null;
            }

            $dom = new DOMDocument();

            @$dom->loadHTML($response->body());

            $data = null;

            foreach ($dom->getElementsByTagName('script') as $script) {
                if (
                    strtolower($script->getAttribute('type'))
                    !== 'application/ld+json'
                ) {
                    continue;
                }

                $decoded = json_decode($script->textContent, true);

                if (is_array($decoded)) {
                    $data = $this->findJobPosting($decoded);

                    if ($data !== null) {
                        break;
                    }
                }
            }

            if ($data === null) {
                return null;
            }

            $title = trim((string) ($data['title'] ?? ''));

            $normalizedTitle = html_entity_decode(
                $title,
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );

            if ($normalizedTitle === '') {
                return null;
            }

            $description = $this->cleanDescription(
                (string) ($data['description'] ?? '')
            );

            // Exclui Banco de Talentos antes de retornar ao sistema.
            if ($this->isTalentPool($normalizedTitle, $description)) {
                Log::info('Gupy: anúncio de Banco de Talentos descartado.', [
                    'company' => $this->companyName,
                    'job_id' => $jobId,
                    'title' => $normalizedTitle,
                ]);

                return null;
            }

            $jobLocation = $data['jobLocation'] ?? [];
            $address = [];

            if (is_array($jobLocation)) {
                if (array_is_list($jobLocation)) {
                    $jobLocation = $jobLocation[0] ?? [];
                }

                $address = $jobLocation['address'] ?? [];
            }

            if (!is_array($address)) {
                $address = [];
            }

            $city = $address['addressLocality'] ?? null;
            $state = $address['addressRegion'] ?? null;
            $country = $address['addressCountry'] ?? 'Brasil';

            if (is_array($country)) {
                $country = $country['name'] ?? 'Brasil';
            }

            if (is_array($state)) {
                $state = $state['name'] ?? null;
            }

            $location = implode(', ', array_filter([
                $city,
                $state,
                $country,
            ]));

            return [
                'title' => $title,
                'company' => $this->companyName,
                'description' => $description,
                'location' => $location ?: 'Não informado',
                'work_model' => $this->getWorkModel($data),
                'employment_type' => $this->getEmploymentType(
                    $data['employmentType'] ?? null
                ),
                'salary' => null,
                'application_url' => $url,
                'external_id' => $jobId,
                'original_published_at' => $data['datePosted'] ?? null,
                'city' => $city,
                'state' => $state,
                'state_code' => null,
                'country' => $country,
                'country_code' => 'BR',
                'latitude' => null,
                'longitude' => null,
            ];
        } catch (Throwable $exception) {
            Log::warning('Gupy: não foi possível consultar uma vaga.', [
                'company' => $this->companyName,
                'job_id' => $jobId,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function isTalentPool(
        string $title,
        string $description
    ): bool {
        $pattern = '/\bbanco\s+de\s+talentos?\b/i';

        return preg_match($pattern, $title) === 1
            || preg_match($pattern, $description) === 1;
    }

    private function findJobPosting(array $data): ?array
    {
        $type = $data['@type'] ?? null;

        if (
            $type === 'JobPosting'
            || (is_array($type) && in_array('JobPosting', $type, true))
        ) {
            return $data;
        }

        foreach ($data as $value) {
            if (!is_array($value)) {
                continue;
            }

            if (array_is_list($value)) {
                foreach ($value as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $result = $this->findJobPosting($item);

                    if ($result !== null) {
                        return $result;
                    }
                }

                continue;
            }

            $result = $this->findJobPosting($value);

            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    private function getWorkModel(array $data): ?string
    {
        $jobLocationType = strtoupper(
            (string) ($data['jobLocationType'] ?? '')
        );

        if ($jobLocationType === 'TELECOMMUTE') {
            return 'remote';
        }

        $description = mb_strtolower(
            (string) ($data['description'] ?? '')
        );

        if (str_contains($description, 'trabalho remoto')) {
            return 'remote';
        }

        if (
            str_contains($description, 'híbrido')
            || str_contains($description, 'hibrido')
        ) {
            return 'hybrid';
        }

        if (str_contains($description, 'presencial')) {
            return 'onsite';
        }

        return null;
    }

    private function getEmploymentType(mixed $type): ?string
    {
        if (is_array($type)) {
            $type = $type[0] ?? null;
        }

        return match (strtoupper((string) $type)) {
            'FULL_TIME' => 'full_time',
            'PART_TIME' => 'part_time',
            'CONTRACTOR' => 'contract',
            'TEMPORARY' => 'temporary',
            'INTERN' => 'internship',
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

    private function http()
    {
        return Http::accept('text/html')
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; TIVagas/1.0)',
            ])
            ->timeout(30)
            ->withOptions([
                'verify' => $this->caBundle ?: true,
            ]);
    }
}
