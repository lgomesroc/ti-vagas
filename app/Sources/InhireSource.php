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

        // Filtra apenas a área profissional.
        // Senioridade é responsabilidade dos filtros gerais do projeto.
        if (!$this->isTechnologyVacancy($title, $description)) {
            return null;
        }

        $rawLocation = $this->extractLocationText(
            $details['location'] ?? $job['location'] ?? null
        );

        $workplaceType = $details['workplaceType']
            ?? $job['workplaceType']
            ?? null;

        $workplaceType = is_string($workplaceType)
            ? $workplaceType
            : null;

        $workModel = $this->normalizeWorkModel($workplaceType);

        $geographicFields = $this->extractGeographicFields(
            $details,
            $job
        );

        $isBrazilWideRemote = $this->isBrazilWideRemote(
            $rawLocation,
            $workplaceType
        );

        // A InHire representa vagas remotas nacionais como "BR".
        // Para o portal, isso significa Brasil inteiro.
        $location = $isBrazilWideRemote
            ? 'Brasil'
            : $this->normalizeLocation($rawLocation);

        // Não deixa o filtro geográfico eliminar vagas remotas nacionais.
        if (
            !$isBrazilWideRemote
            && $this->locationFilter !== null
            && !$this->locationFilter->isEligibleForCollection(
                $location,
                $title,
                $description,
                $workModel
            )
        ) {
            return null;
        }

        $publishedAt = $details['publishedAt']
            ?? $job['publishedAt']
            ?? null;

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

            'city' => $geographicFields['city'],
            'state' => $geographicFields['state'],
            'state_code' => $geographicFields['state_code'],
            'country' => $geographicFields['country'],
            'country_code' => $geographicFields['country_code'],
            'latitude' => $geographicFields['latitude'],
            'longitude' => $geographicFields['longitude'],
        ];
    }

    /**
     * Extrai somente informações geográficas fornecidas em campos
     * estruturados pela API.
     *
     * Não interpreta strings como "Niterói, RJ, Brasil".
     */
    private function extractGeographicFields(
        array $details,
        array $job
    ): array {
        $sources = [
            $details,
            $job,
        ];

        foreach (['location', 'address', 'addressDetails'] as $container) {
            foreach ([$details, $job] as $record) {
                if (isset($record[$container]) && is_array($record[$container])) {
                    $sources[] = $record[$container];
                }
            }
        }

        return [
            'city' => $this->extractStructuredValue(
                $sources,
                ['city', 'cityName', 'addressLocality'],
                ['name', 'value']
            ),

            'state' => $this->extractStructuredValue(
                $sources,
                ['state', 'stateName', 'addressRegion'],
                ['name', 'value']
            ),

            'state_code' => $this->extractStructuredValue(
                $sources,
                ['stateCode', 'state_code', 'uf'],
                ['code', 'value']
            ),

            'country' => $this->extractStructuredValue(
                $sources,
                ['country', 'countryName', 'addressCountry'],
                ['name', 'value']
            ),

            'country_code' => $this->extractStructuredValue(
                $sources,
                ['countryCode', 'country_code'],
                ['code', 'value']
            ),

            'latitude' => $this->extractNumericValue(
                $sources,
                ['latitude', 'lat']
            ),

            'longitude' => $this->extractNumericValue(
                $sources,
                ['longitude', 'long', 'lng', 'lon']
            ),
        ];
    }

    private function extractStructuredValue(
        array $sources,
        array $keys,
        array $nestedKeys = []
    ): ?string {
        foreach ($sources as $source) {
            foreach ($keys as $key) {
                if (!array_key_exists($key, $source)) {
                    continue;
                }

                $value = $source[$key];

                if (is_array($value)) {
                    foreach ($nestedKeys as $nestedKey) {
                        if (
                            isset($value[$nestedKey])
                            && is_string($value[$nestedKey])
                            && trim($value[$nestedKey]) !== ''
                        ) {
                            return trim($value[$nestedKey]);
                        }
                    }

                    continue;
                }

                if (is_string($value) || is_numeric($value)) {
                    $value = trim((string) $value);

                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }

        return null;
    }

    private function extractNumericValue(
        array $sources,
        array $keys
    ): int|float|null {
        foreach ($sources as $source) {
            foreach ($keys as $key) {
                if (
                    array_key_exists($key, $source)
                    && is_numeric($source[$key])
                ) {
                    return (float) $source[$key];
                }
            }
        }

        return null;
    }

    private function extractLocationText(mixed $location): string
    {
        return is_string($location) ? trim($location) : '';
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

    private function isBrazilWideRemote(
        string $location,
        ?string $workplaceType
    ): bool {
        $model = Str::lower(trim((string) $workplaceType));

        $normalizedLocation = Str::ascii(
            Str::lower(trim($location))
        );

        return $model === 'remote'
            && in_array(
                $normalizedLocation,
                ['br', 'brasil', 'brazil'],
                true
            );
    }

    private function normalizeLocation(string $location): string
    {
        $location = trim($location);

        if ($location === '') {
            return 'Não informada';
        }

        $location = preg_replace(
            '/,\s*BR$/i',
            ', Brasil',
            $location
        ) ?? $location;

        return $location;
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
            '/desenvolvedor|developer|programador|programmer|software|backend|back-end|frontend|front-end|full.?stack|analista de sistemas|analista de suporte|suporte ti|suporte tecnico|suporte n[1-3]|help.?desk|service desk|infraestrutura|infra|redes|cloud|devops|banco de dados|database|analista de dados|data analyst|engenheiro de dados|data engineer|business intelligence|quality assurance|testes de software|qa|seguranca da informacao|cyber security|sistemas|salesforce|consultor sap|administrador de sistemas|administrador salesforce|automacao de sistemas/',
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
