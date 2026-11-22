<?php
namespace App\Sources;

use App\Contracts\VacancySource;
use App\Services\VacancyLocationFilter;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class GupyGlobalSource implements VacancySource
{
    public const LAST_SUCCESSFUL_AT_KEY = 'vacancies:gupy-global:last-successful-at';
    private const API_URL = 'https://portal.gupy.io/api/job-search/jobs';

    private array $diagnostics = [
        'terms' => 0, 'pages' => 0, 'results_received' => 0,
        'unique_jobs' => 0, 'missing_publication_date' => 0, 'outside_lookback' => 0,
    ];

    public function __construct(
        private readonly array $terms,
        private readonly int $pageSize = 50,
        private readonly int $maxPages = 5,
        private readonly int $initialLookbackDays = 60,
        private readonly int $overlapMinutes = 60,
        private readonly int $requestDelayMs = 250,
        private readonly ?string $caBundle = null,
        private readonly ?VacancyLocationFilter $locationFilter = null,
    ) {}

    public function name(): string { return 'gupy-global'; }
    public function diagnostics(): array { return $this->diagnostics; }

    public function fetch(): iterable
    {
        $terms = array_values(array_unique(array_filter(
            array_map(static fn ($term): string => trim((string) $term), $this->terms),
            static fn (string $term): bool => $term !== ''
        )));
        if ($terms === []) {
            throw new RuntimeException('Nenhum termo de busca global da Gupy foi configurado.');
        }

        $since = $this->lastSuccessfulWindowStart();
        $jobs = [];
        $this->diagnostics['terms'] = count($terms);

        foreach ($terms as $term) {
            for ($page = 0; $page < max(1, $this->maxPages); $page++) {
                $payload = $this->request([
                    'jobName' => $term,
                    'limit' => max(1, $this->pageSize),
                    'offset' => $page * max(1, $this->pageSize),
                    'sortBy' => 'publishedDate',
                    'sortOrder' => 'desc',
                ]);
                $items = $payload['data'] ?? null;
                if (!is_array($items)) {
                    throw new RuntimeException('A API pública da Gupy retornou um formato inesperado.');
                }

                $this->diagnostics['pages']++;
                $this->diagnostics['results_received'] += count($items);
                if ($items === []) break;

                $pastWindow = false;
                foreach ($items as $item) {
                    if (!is_array($item)) continue;
                    $publishedAt = $this->parsePublishedAt($item['publishedDate'] ?? null);
                    if ($publishedAt === null) {
                        $this->diagnostics['missing_publication_date']++;
                        continue;
                    }
                    if ($publishedAt->lt($since)) {
                        $this->diagnostics['outside_lookback']++;
                        $pastWindow = true;
                        break;
                    }
                    $job = $this->normalizeJob($item, $publishedAt);
                    if ($job !== null) $jobs[$job['external_id']] = $job;
                }

                if ($pastWindow || count($items) < max(1, $this->pageSize)) break;
                if ($this->requestDelayMs > 0) usleep($this->requestDelayMs * 1000);
            }
        }

        $this->diagnostics['unique_jobs'] = count($jobs);
        Log::info('Gupy global: coleta concluída.', [
            'since' => $since->toIso8601String(),
            'diagnostics' => $this->diagnostics,
        ]);
        return array_values($jobs);
    }

    private function lastSuccessfulWindowStart(): Carbon
    {
        $last = Cache::get(self::LAST_SUCCESSFUL_AT_KEY);
        if (is_string($last) && trim($last) !== '') {
            try {
                return Carbon::parse($last)->subMinutes(max(0, $this->overlapMinutes));
            } catch (Throwable) {
                Log::warning('Gupy global: data da última coleta inválida; usando janela inicial.');
            }
        }
        return now()->subDays(max(1, $this->initialLookbackDays));
    }

    private function request(array $params): array
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $response = Http::acceptJson()
                ->withUserAgent('Mozilla/5.0 (compatible; TIVagas/1.0)')
                ->timeout(25)
                ->withOptions(['verify' => $this->caBundle ?: true])
                ->get(self::API_URL, $params);

            if ($response->status() === 429 && $attempt < 3) {
                sleep($attempt * 2);
                continue;
            }
            if (!$response->successful()) {
                throw new RuntimeException('A API pública da Gupy respondeu HTTP ' . $response->status() . '.');
            }
            $payload = $response->json();
            if (!is_array($payload)) {
                throw new RuntimeException('A API pública da Gupy retornou JSON inválido.');
            }
            return $payload;
        }
        throw new RuntimeException('A API pública da Gupy manteve o bloqueio de requisições (HTTP 429).');
    }

    private function normalizeJob(array $item, Carbon $publishedAt): ?array
    {
        $id = $item['id'] ?? null;
        $title = trim((string) ($item['name'] ?? ''));
        $company = trim((string) ($item['careerPageName'] ?? $item['careerPageSlug'] ?? ''));
        $description = $this->cleanDescription((string) ($item['description'] ?? ''));
        $url = trim((string) ($item['jobUrl'] ?? ''));

        if ($url === '' && !empty($item['careerPageSlug']) && is_scalar($id)) {
            $url = 'https://' . $item['careerPageSlug'] . '.gupy.io/jobs/' . $id;
        } elseif (str_starts_with($url, '/')) {
            $url = 'https://portal.gupy.io' . $url;
        }
        $url = preg_replace('/\?.*$/', '', $url) ?? $url;

        if (!is_scalar($id) || $title === '' || $company === '' || $description === ''
            || !filter_var($url, FILTER_VALIDATE_URL)) return null;

        $city = $this->nullableString($item['city'] ?? null);
        $state = $this->nullableString($item['state'] ?? null);
        $stateCode = $this->nullableString($item['stateCode'] ?? $item['stateShortName'] ?? null);
        if ($city === null || $state === null) {
            [$fallbackCity, $fallbackState] = $this->extractLocation($title, $description);
            $city ??= $fallbackCity;
            $state ??= $fallbackState;
        }
        if ($stateCode === null && $state !== null && preg_match('/^[A-Z]{2}$/i', $state)) {
            $stateCode = strtoupper($state);
        }

        $country = $this->nullableString($item['country'] ?? $item['countryName'] ?? null);
        $location = implode(', ', array_filter([$city, $state, $country]));
        $workplaceType = strtolower(trim((string) ($item['workplaceType'] ?? '')));
        $workModel = match ($workplaceType) {
            'remote', 'home-office' => 'Remoto',
            'hybrid', 'hybrid-work' => 'Híbrido',
            'on-site', 'onsite' => 'Presencial',
            default => null,
        };
        if ($workModel === null && $this->locationFilter !== null) {
            $workModel = $this->locationFilter->classifyWorkModel(
                $title, $location !== '' ? $location : null, $description
            );
        }

        $employmentType = match (strtolower(trim((string) ($item['type'] ?? '')))) {
            'vacancy_type_effective' => 'CLT',
            'vacancy_legal_entity' => 'PJ',
            'vacancy_type_freelancer' => 'Freelance',
            'vacancy_type_internship' => 'Estágio',
            'vacancy_type_trainee' => 'Trainee',
            'vacancy_type_temporary' => 'Temporário',
            default => null,
        };

        return [
            'title' => $title, 'company' => $company, 'description' => $description,
            'location' => $location !== '' ? $location : null,
            'work_model' => $workModel, 'employment_type' => $employmentType,
            'salary' => $item['salary'] ?? null, 'application_url' => $url,
            'external_id' => (string) $id,
            'original_published_at' => $publishedAt->toIso8601String(),
            'city' => $city, 'state' => $state, 'state_code' => $stateCode,
            'country' => $country,
            'country_code' => $this->nullableString($item['countryCode'] ?? null),
        ];
    }

    private function parsePublishedAt(mixed $value): ?Carbon
    {
        if (!is_string($value) || trim($value) === '') return null;
        try { return Carbon::parse($value); } catch (Throwable) { return null; }
    }

    private function cleanDescription(string $description): string
    {
        $description = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $description = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $description) ?? $description;
        $description = preg_replace('/<\/(?:p|div|li|h[1-6])\s*>/i', "\n", $description) ?? $description;
        $description = strip_tags($description);
        $description = preg_replace('/[ \t]+/u', ' ', $description) ?? $description;
        $description = preg_replace('/\n{3,}/u', "\n\n", $description) ?? $description;
        return trim($description);
    }

    private function extractLocation(string $title, string $description): array
    {
        $states = 'AC|AL|AP|AM|BA|CE|DF|ES|GO|MA|MT|MS|MG|PA|PB|PR|PE|PI|RJ|RN|RS|RO|RR|SC|SP|SE|TO';
        $text = $title . "\n" . mb_substr($description, 0, 8000);
        $patterns = [
            '/(?:local(?:idade|ização)?|cidade|base|local de trabalho)\s*[:\-]\s*([^\r\n.;]{2,90})/iu',
            '/([\p{L}][\p{L}\s.\'-]{1,50}?)\s*[-\/,]\s*(' . $states . ')\b/iu',
        ];
        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $text, $matches)) continue;
            $candidate = trim($matches[1], " \t\n\r\0\x0B,.;:-");
            $candidate = preg_replace('/\s*\([^)]*\).*$/u', '', $candidate) ?? $candidate;
            if (preg_match('/^(.+?)\s*[-\/,]\s*(' . $states . ')$/iu', $candidate, $parts)) {
                return [trim($parts[1]), strtoupper($parts[2])];
            }
            if (isset($matches[2])) return [$candidate, strtoupper($matches[2])];
        }
        return [null, null];
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value) && !is_numeric($value)) return null;
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }
}
