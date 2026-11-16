<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Services\VacancyLocationFilter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class QuickinSource implements VacancySource
{
    private const API_BASE_URL = 'https://api.quickin.io/public';

    private readonly VacancyLocationFilter $locationFilter;

    public function __construct(
        private readonly string $companyKey,
        private readonly string $companyName,
        private readonly string $url,
        private readonly ?string $caBundle = null,
        private readonly int $maxPages = 20,
        ?VacancyLocationFilter $locationFilter = null,
    ) {
        $this->locationFilter = $locationFilter ?? new VacancyLocationFilter();
    }

    public function name(): string
    {
        return 'quickin-' . $this->companyKey;
    }

    public function fetch(): iterable
    {
        $html = $this->getCareerPage();
        $accountId = $this->extractAccountId($html);

        if ($accountId === null) {
            Log::warning('Quickin: account_id não encontrado na página pública; fonte ignorada.', [
                'company' => $this->companyName,
                'url' => $this->url,
            ]);
            return [];
        }

        $vacancies = [];
        $seen = [];

        for ($page = 1; $page <= $this->maxPages; $page++) {
            $response = Http::acceptJson()
                ->timeout(60)
                ->withOptions(['verify' => $this->caBundle ?: true])
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; TI-Vagas/1.0)'])
                ->get(self::API_BASE_URL . '/' . $accountId . '/jobs', [
                    'sort' => '-created_at',
                    'page' => $page,
                ]);

            $response->throw();
            $jobs = $this->extractJobs($response->json());

            if ($jobs === []) {
                break;
            }

            foreach ($jobs as $job) {
                if (!is_array($job)) {
                    continue;
                }

                $id = trim((string) ($job['_id'] ?? ''));
                if ($id === '' || isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;
                $vacancy = $this->normalizeJob($job);

                if ($vacancy !== null) {
                    $vacancies[] = $vacancy;
                }
            }

            if (count($jobs) < 30) {
                break;
            }
        }

        Log::info('Quickin: coleta concluída.', [
            'company' => $this->companyName,
            'account_id' => $accountId,
            'jobs_importable' => count($vacancies),
        ]);

        return $vacancies;
    }

    private function getCareerPage(): string
    {
        $response = Http::timeout(45)
            ->withOptions(['verify' => $this->caBundle ?: true])
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; TI-Vagas/1.0)',
                'Accept' => 'text/html,application/xhtml+xml',
            ])
            ->get($this->url);

        $response->throw();

        if (trim($response->body()) === '') {
            throw new RuntimeException('Página pública Quickin vazia: ' . $this->companyName);
        }

        return $response->body();
    }

    private function extractAccountId(string $html): ?string
    {
        $patterns = [
            "/[\\"']account_id[\\"']\\s*:\\s*[\\"']([a-f0-9]{24})[\\"']/i",
            "/[\\"']accountId[\\"']\\s*:\\s*[\\"']([a-f0-9]{24})[\\"']/i",
            "/data-account-id\\s*=\\s*[\\"']([a-f0-9]{24})[\\"']/i",
            '/account_id=([a-f0-9]{24})/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches) === 1) {
                return strtolower($matches[1]);
            }
        }

        return null;
    }

    private function extractJobs(mixed $payload): array
    {
        if (!is_array($payload)) {
            throw new RuntimeException('Resposta inválida da API pública Quickin.');
        }

        if (array_is_list($payload)) {
            return $payload;
        }

        foreach (['docs', 'jobs', 'data', 'results'] as $key) {
            if (!isset($payload[$key]) || !is_array($payload[$key])) {
                continue;
            }

            if (array_is_list($payload[$key])) {
                return $payload[$key];
            }

            foreach (['docs', 'jobs', 'data', 'results'] as $nested) {
                if (isset($payload[$key][$nested]) && is_array($payload[$key][$nested])) {
                    return $payload[$key][$nested];
                }
            }
        }

        return [];
    }

    private function normalizeJob(array $job): ?array
    {
        $id = trim((string) ($job['_id'] ?? ''));
        $title = $this->cleanText($job['title'] ?? '');
        $description = $this->cleanDescription(implode("\n\n", array_filter([
            $job['description'] ?? null,
            $job['requirements'] ?? null,
            $job['benefits'] ?? null,
        ], fn ($value) => is_string($value) && trim($value) !== '')));

        if (
            $id === ''
            || $title === ''
            || $description === ''
            || ($job['publicate'] ?? null) !== 'published'
            || ($job['confidential'] ?? false) === true
            || (isset($job['status']) && !in_array($job['status'], ['open', 'published'], true))
        ) {
            return null;
        }

        // created_at é a data original de criação/publicação documentada pela Quickin.
        $publishedAt = $this->normalizeDate($job['created_at'] ?? null);
        if ($publishedAt === null) {
            return null;
        }

        $city = $this->cleanText($job['city'] ?? '');
        $region = $this->cleanText($job['region'] ?? '');
        $countryCode = strtoupper($this->cleanText($job['country'] ?? ''));
        $country = $countryCode === 'BR' ? 'Brasil' : $countryCode;
        $location = implode(', ', array_filter([$city, $region, $country]));

        $workModel = $this->normalizeWorkModel($job['workplace_type'] ?? null)
            ?? $this->locationFilter->classifyWorkModel($title, $location, $description);

        if (!$this->locationFilter->isEligibleForCollection($location, $title, $description, $workModel)) {
            return null;
        }

        $applicationUrl = trim((string) ($job['career_url'] ?? ''));
        if (!filter_var($applicationUrl, FILTER_VALIDATE_URL)) {
            $slug = trim((string) parse_url($this->url, PHP_URL_PATH), '/');
            $applicationUrl = 'https://jobs.quickin.io/' . $slug . '/apply?job_id=' . rawurlencode($id);
        }

        $contract = $job['contract_id']['name'] ?? null;

        return [
            'title' => $title,
            'company' => $this->companyName,
            'description' => $description,
            'location' => $location !== '' ? $location : null,
            'city' => $city !== '' ? $city : null,
            'state' => $region !== '' ? $region : null,
            'country' => $country !== '' ? $country : null,
            'country_code' => $countryCode !== '' ? $countryCode : null,
            'work_model' => $workModel,
            'employment_type' => $this->cleanText($contract) ?: null,
            'salary' => $this->normalizeSalary($job),
            'application_url' => $applicationUrl,
            'external_id' => $this->name() . ':' . $id,
            'original_published_at' => $publishedAt,
        ];
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC')->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeWorkModel(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return match (Str::lower(Str::ascii(trim($value)))) {
            'remote', 'remoto', 'home office' => 'Remoto',
            'hybrid', 'hibrido' => 'Híbrido',
            'on-site', 'onsite', 'presencial' => 'Presencial',
            default => null,
        };
    }

    private function normalizeSalary(array $job): ?string
    {
        $minimum = $job['remuneration'] ?? null;
        $maximum = $job['remuneration_max'] ?? null;

        if (!is_numeric($minimum) && !is_numeric($maximum)) {
            return null;
        }

        $format = static fn ($value): string => 'R$ ' . number_format((float) $value, 2, ',', '.');
        $range = is_numeric($minimum) && is_numeric($maximum) && (float) $minimum !== (float) $maximum
            ? $format($minimum) . ' - ' . $format($maximum)
            : $format(is_numeric($minimum) ? $minimum : $maximum);

        $period = $this->cleanText($job['remuneration_period'] ?? '');
        return $range . ($period !== '' ? ' / ' . $period : '');
    }

    private function cleanDescription(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/<\s*br\s*\/?>|<\/\s*p\s*>|<\/\s*div\s*>|<\/\s*h[1-6]\s*>/i', "\n", $value);
        $value = preg_replace('/<\s*li\b[^>]*>/i', "\n- ", $value);
        $value = strip_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[ \t]+/', ' ', $value);
        $value = preg_replace('/ *\n */', "\n", $value);
        $value = preg_replace('/\n{3,}/', "\n\n", $value);

        return trim($value);
    }

    private function cleanText(mixed $value): string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }

        $value = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }
}
