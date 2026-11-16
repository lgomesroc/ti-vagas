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

class PlooralSource implements VacancySource
{
    private const API_BASE_URL = 'https://api.enlizt.com/v1/public/feed';

    private readonly VacancyLocationFilter $locationFilter;

    public function __construct(
        private readonly string $companyKey,
        private readonly string $companyName,
        private readonly string $subdomain,
        private readonly ?string $caBundle = null,
        private readonly int $maxJobs = 200,
        ?VacancyLocationFilter $locationFilter = null,
    ) {
        $this->locationFilter = $locationFilter ?? new VacancyLocationFilter();
    }

    public function name(): string
    {
        return 'plooral-' . $this->companyKey;
    }

    public function fetch(): iterable
    {
        $baseUrl = self::API_BASE_URL . '/' . rawurlencode($this->subdomain) . '/positions';
        $vacancies = [];
        $processed = 0;
        $page = 1;
        $limit = 100;

        while ($processed < $this->maxJobs) {
            $response = $this->http()->get($baseUrl, [
                'show' => 'hiring',
                'limit' => min($limit, $this->maxJobs - $processed),
                'page' => $page,
            ]);
            $response->throw();

            $positions = $this->extractList($response->json());
            if ($positions === []) {
                break;
            }

            foreach ($positions as $position) {
                if ($processed >= $this->maxJobs) {
                    break;
                }
                if (!is_array($position)) {
                    continue;
                }

                $processed++;
                $code = trim((string) ($position['code'] ?? ''));
                if ($code === '') {
                    continue;
                }

                try {
                    $detailResponse = $this->http()->get(
                        $baseUrl . '/' . rawurlencode($code)
                    );
                    if (!$detailResponse->successful()) {
                        continue;
                    }

                    $detail = $this->extractObject($detailResponse->json());
                    if ($detail === []) {
                        continue;
                    }

                    $vacancy = $this->normalizePosition(array_merge($position, $detail));
                    if ($vacancy !== null) {
                        $vacancies[] = $vacancy;
                    }
                } catch (Throwable $exception) {
                    Log::warning('Plooral: falha ao consultar detalhes da vaga.', [
                        'company' => $this->companyName,
                        'code' => $code,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            if (count($positions) < $limit) {
                break;
            }
            $page++;
        }

        Log::info('Plooral: coleta concluída.', [
            'company' => $this->companyName,
            'positions_processed' => $processed,
            'jobs_importable' => count($vacancies),
        ]);

        return $vacancies;
    }

    private function normalizePosition(array $job): ?array
    {
        $code = trim((string) ($job['code'] ?? ''));
        $title = $this->cleanText($job['title'] ?? '');
        $description = $this->cleanDescription($job['description'] ?? '');

        if (
            $code === ''
            || $title === ''
            || $description === ''
            || ($job['active'] ?? true) !== true
            || ($job['allowNewApplications'] ?? true) !== true
        ) {
            return null;
        }

        if (!$this->isTechnologyVacancy($title, $description)) {
            return null;
        }

        $publishedAt = $this->normalizeDate($job['date'] ?? null);
        if ($publishedAt === null) {
            Log::warning('Plooral: vaga ignorada por não possuir data original válida.', [
                'company' => $this->companyName,
                'code' => $code,
            ]);
            return null;
        }

        $location = $this->cleanText($job['location'] ?? '');
        $workModel = $this->normalizeWorkplace($job['workplace'] ?? null);
        if ($location === '' && $workModel === 'Remoto') {
            $location = 'Brasil';
        }

        $workModel ??= $this->locationFilter->classifyWorkModel(
            $title,
            $location,
            $description
        );

        if (!$this->locationFilter->isEligibleForCollection(
            $location,
            $title,
            $description,
            $workModel
        )) {
            return null;
        }

        $applicationUrl = trim((string) ($job['link'] ?? ''));
        if (!filter_var($applicationUrl, FILTER_VALIDATE_URL)) {
            return null;
        }

        return [
            'title' => $title,
            'company' => $this->companyName,
            'description' => $description,
            'location' => $location !== '' ? $location : null,
            'work_model' => $workModel,
            'employment_type' => $this->normalizeEmploymentType($job['type'] ?? null),
            'salary' => $this->cleanText($job['salary'] ?? '') ?: null,
            'application_url' => $applicationUrl,
            'external_id' => $this->name() . ':' . $code,
            'original_published_at' => $publishedAt,
        ];
    }

    private function extractList(mixed $payload): array
    {
        if (is_array($payload) && array_is_list($payload)) {
            return $payload;
        }

        if (is_array($payload)) {
            foreach (['positions', 'data', 'results', 'items'] as $key) {
                if (isset($payload[$key]) && is_array($payload[$key])) {
                    if (array_is_list($payload[$key])) {
                        return $payload[$key];
                    }
                    foreach (['positions', 'data', 'results', 'items'] as $nested) {
                        if (isset($payload[$key][$nested]) && is_array($payload[$key][$nested])) {
                            return $payload[$key][$nested];
                        }
                    }
                }
            }
        }

        throw new RuntimeException('Resposta de listagem inválida da API pública Plooral.');
    }

    private function extractObject(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        foreach (['position', 'data', 'result'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $payload[$key];
            }
        }

        return $payload;
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

    private function normalizeWorkplace(mixed $value): ?string
    {
        return match (Str::lower(trim((string) $value))) {
            'remote' => 'Remoto',
            'hybrid' => 'Híbrido',
            'on-site' => 'Presencial',
            default => null,
        };
    }

    private function normalizeEmploymentType(mixed $value): ?string
    {
        return match (Str::lower(trim((string) $value))) {
            'full_time' => 'Tempo integral',
            'part_time' => 'Meio período',
            'contractor' => 'Contrato',
            'temporary' => 'Temporário',
            'inter', 'volunteer', 'per_diem' => null,
            default => null,
        };
    }

    private function isTechnologyVacancy(string $title, string $description): bool
    {
        $normalizedTitle = Str::ascii(mb_strtolower($title));
        $normalizedDescription = Str::ascii(mb_strtolower($description));

        if (preg_match('/\b(dados|data|bi|business intelligence|analytics|analise de dados|ciencia de dados|machine learning|engenharia de dados|crm|erp|sap|abap)\b/i', $normalizedTitle)) {
            return false;
        }

        if (preg_match('/\b(desenvolvedor|developer|programador|software engineer|engenheiro de software|backend|back-end|frontend|front-end|full.?stack|qa|quality assurance|tester|analista de sistemas|administrador de sistemas|suporte tecnico|suporte ti|service desk|help.?desk|infraestrutura|infra|redes|network|cloud|devops|sre|seguranca da informacao|ciberseguranca|cybersecurity|soc)\b/i', $normalizedTitle)) {
            return true;
        }

        return preg_match('/\b(analista|especialista|tecnico|tecnica|engenheiro|engenheira|coordenador|coordenadora)\b/i', $normalizedTitle)
            && preg_match('/\b(desenvolvimento de software|desenvolvimento de sistemas|infraestrutura de ti|redes de computadores|administracao de servidores|suporte de ti|suporte tecnico|devops|cloud|ciberseguranca|seguranca da informacao|quality assurance)\b/i', $normalizedDescription);
    }

    private function cleanDescription(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/<\s*br\s*\/?>|<\/\s*p\s*>|<\/\s*div\s*>|<\/\s*h[1-6]\s*>/i', "\n", $value);
        $value = preg_replace('/<\s*li\b[^>]*>/i', "\n- ", $value);
        $value = strip_tags($value);
        return trim(preg_replace('/\n{3,}/', "\n\n", preg_replace('/[ \t]+/', ' ', $value) ?? '') ?? '');
    }

    private function cleanText(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    private function http()
    {
        return Http::acceptJson()
            ->timeout(45)
            ->withHeaders(['User-Agent' => 'TI-Vagas/1.0'])
            ->withOptions(['verify' => $this->caBundle ?: true]);
    }
}
