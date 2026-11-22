<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Services\VacancyLocationFilter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AblerSource implements VacancySource
{
    private const API_URL = 'https://candidatos.abler.com.br/api/candidate/v2/vacancies';

    private readonly VacancyLocationFilter $locationFilter;
    private array $diagnostics = [
        'pages_requested' => 0,
        'records_received' => 0,
        'vacancies_importable' => 0,
        'ignored_by_filter' => 0,
    ];

    public function __construct(
        private readonly ?string $caBundle = null,
        private readonly int $maxPages = 30,
        private readonly int $pageSize = 100,
        private readonly int $maxJobs = 1500,
        private readonly int $initialLookbackDays = 60,
        ?VacancyLocationFilter $locationFilter = null,
    ) {
        $this->locationFilter = $locationFilter ?? new VacancyLocationFilter();
    }

    public function name(): string
    {
        return 'abler-global';
    }

    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    public function fetch(): iterable
    {
        $vacancies = [];
        $seen = [];
        $cutoff = CarbonImmutable::now()->subDays($this->initialLookbackDays);
        $client = Http::acceptJson()
            ->timeout(45)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; TI-Vagas/1.0)'])
            ->withOptions(['verify' => $this->caBundle ?: true]);

        for ($page = 1; $page <= $this->maxPages && count($seen) < $this->maxJobs; $page++) {
            $response = $client->get(self::API_URL, [
                'page' => $page,
                'per_page' => $this->pageSize,
                'provider' => '',
            ]);
            $response->throw();

            $payload = $response->json();
            if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
                throw new RuntimeException('Resposta inválida da API pública de vagas da Abler.');
            }

            $this->diagnostics['pages_requested']++;
            $records = $payload['data'];
            $this->diagnostics['records_received'] += count($records);

            if ($records === []) {
                break;
            }

            foreach ($records as $record) {
                if (!is_array($record) || ($record['type'] ?? null) !== 'vacancy') {
                    continue;
                }

                $job = is_array($record['attributes'] ?? null)
                    ? $record['attributes']
                    : $record;
                $id = trim((string) ($job['id'] ?? $record['id'] ?? ''));
                if ($id === '' || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;

                $publishedAt = $this->normalizeDate($job['created_at'] ?? null);
                if ($publishedAt !== null && CarbonImmutable::parse($publishedAt)->lt($cutoff)) {
                    continue;
                }

                $vacancy = $this->normalizeVacancy($job, $id, $publishedAt);
                if ($vacancy === null) {
                    $this->diagnostics['ignored_by_filter']++;
                    continue;
                }

                $vacancies[] = $vacancy;
                if (count($vacancies) >= $this->maxJobs) {
                    break 2;
                }
            }

            $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
            $next = $meta['next'] ?? null;
            $last = (int) ($meta['last'] ?? 0);

            if ($next === null || $next === false || $page >= $last) {
                break;
            }
        }

        $this->diagnostics['vacancies_importable'] = count($vacancies);
        Log::info('Abler: coleta global concluída.', $this->diagnostics);

        return $vacancies;
    }

    private function normalizeVacancy(array $job, string $id, ?string $publishedAt): ?array
    {
        $title = $this->cleanText($job['title'] ?? '');
        $description = $this->cleanDescription($job['description'] ?? '');
        $status = Str::lower($this->cleanText($job['status'] ?? 'published'));

        if ($title === '' || $description === '' || !in_array($status, ['published', 'published '], true) || $publishedAt === null) {
            return null;
        }

        if (!$this->isTechnologyVacancy($title, $description)) {
            return null;
        }

        $company = $job['company'] ?? null;
        if (is_array($company)) {
            $company = $company['name'] ?? $company['title'] ?? null;
        }
        $company = $this->cleanText($company);
        if ($company === '') {
            $company = 'Empresa não informada';
        }

        $city = $this->cleanText($job['city'] ?? '');
        $state = $this->cleanText($job['state'] ?? '');
        $country = $this->cleanText($job['country'] ?? '');
        $locationParts = array_filter([$city, $state, $country], static fn ($part) => $part !== '');
        $location = implode(' - ', array_unique($locationParts));

        $workplace = Str::lower($this->cleanText(
            is_array($job['workplace'] ?? null)
                ? ($job['workplace']['type'] ?? '')
                : ($job['workplace_type'] ?? $job['work_type'] ?? $job['workplace'] ?? '')
        ));
        $workModel = match (true) {
            str_contains($workplace, 'remot') => 'Remoto',
            str_contains($workplace, 'hibr') => 'Híbrido',
            str_contains($workplace, 'presenc'), str_contains($workplace, 'on-site') => 'Presencial',
            default => null,
        };
        $workModel ??= $this->locationFilter->classifyWorkModel($title, $location, $description);

        if (!$this->locationFilter->isEligibleForCollection($location, $title, $description, $workModel)) {
            return null;
        }

        $applicationUrl = trim((string) ($job['url'] ?? ''));
        if (!filter_var($applicationUrl, FILTER_VALIDATE_URL)) {
            $applicationUrl = 'https://abler.in/v' . rawurlencode($id);
        }

        return [
            'title' => $title,
            'company' => $company,
            'description' => $description,
            'location' => $location !== '' ? $location : null,
            'work_model' => $workModel,
            'employment_type' => $this->normalizeEmploymentType($job['employment_type'] ?? $job['contract_type'] ?? null),
            'salary' => $this->cleanText($job['salary'] ?? '') ?: null,
            'application_url' => $applicationUrl,
            'external_id' => 'abler-global:' . $id,
            'original_published_at' => $publishedAt,
        ];
    }

    private function isTechnologyVacancy(string $title, string $description): bool
    {
        $text = Str::ascii(mb_strtolower($title . ' ' . mb_substr($description, 0, 5000)));

        if (preg_match('/\b(pleno|senior|sênior|staff|principal|lead|lider|líder|coordenador|gerente|director|diretor|head)\b/i', $text)) {
            return false;
        }

        return (bool) preg_match('/\b(desenvolvedor|desenvolvedora|developer|programador|programadora|software engineer|engenheiro de software|backend|back-end|frontend|front-end|full.?stack|analista de sistemas|analista de suporte|suporte tecnico|suporte de ti|help.?desk|service desk|infraestrutura|infra|redes de computadores|analista de redes|devops|cloud|sysadmin|qa|quality assurance|analista de testes|estagio|estagiario|estagiaria|internship|intern)\b/i', $title);
    }

    private function normalizeEmploymentType(mixed $value): ?string
    {
        $value = Str::lower($this->cleanText($value));
        return match (true) {
            str_contains($value, 'estagio'), str_contains($value, 'intern') => 'Estágio',
            str_contains($value, 'pj'), str_contains($value, 'contract') => 'Contrato',
            str_contains($value, 'tempor') => 'Temporário',
            str_contains($value, 'part'), str_contains($value, 'meio periodo') => 'Meio período',
            str_contains($value, 'full'), str_contains($value, 'integral'), str_contains($value, 'clt') => 'Tempo integral',
            default => null,
        };
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    private function cleanDescription(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/<\s*br\s*\/?\s*>|<\/\s*(p|div|h[1-6])\s*>/i', "\n", $value);
        $value = preg_replace('/<\s*li\b[^>]*>/i', "\n- ", $value);
        $value = strip_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\n{3,}/', "\n\n", preg_replace('/[ \t]+/', ' ', $value) ?? '') ?? '');
    }

    private function cleanText(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }
}
