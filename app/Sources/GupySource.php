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
        private readonly bool $technologyOnly = false,
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

                $job = $this->fetchJob($baseUrl, $jobId);

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
        $data = $this->extractNextData($html);

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

        $dom = new DOMDocument();

        @$dom->loadHTML($html);

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

            $html = $response->body();
            $pageData = $this->extractNextData($html);

            // Formato atual da Gupy: props.pageProps.job.
            $data = $pageData['props']['pageProps']['job'] ?? null;

            // Compatibilidade com páginas que utilizam JSON-LD.
            if (!is_array($data)) {
                $dom = new DOMDocument();

                @$dom->loadHTML($html);

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
            }

            if (!is_array($data)) {
                Log::warning('Gupy: dados da vaga não encontrados.', [
                    'company' => $this->companyName,
                    'job_id' => $jobId,
                ]);

                return null;
            }

            $title = trim((string) (
                $data['name'] ?? $data['title'] ?? ''
            ));

            $title = html_entity_decode(
                $title,
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );

            if ($title === '') {
                return null;
            }

            $descriptionParts = array_filter([
                $data['description'] ?? null,
                $data['prerequisites'] ?? null,
                $data['responsibilities'] ?? null,
                $data['relevantExperiences'] ?? null,
            ], fn ($value) => is_string($value) && trim($value) !== '');

            $description = $this->cleanDescription(
                implode("\n\n", $descriptionParts)
            );

            if ($this->isTalentPool($title, $description)) {
                Log::info('Gupy: anúncio de Banco de Talentos descartado.', [
                    'company' => $this->companyName,
                    'job_id' => $jobId,
                    'title' => $title,
                ]);

                return null;
            }

            if (
                $this->technologyOnly
                && !$this->isTechnologyVacancy($title, $description)
            ) {
                return null;
            }

            // Localização no formato atual da Gupy.
            $city = $data['addressCity'] ?? null;
            $state = $data['addressState'] ?? null;
            $stateCode = $data['addressStateShortName'] ?? null;
            $country = $data['addressCountry'] ?? 'Brasil';
            $countryCode = $data['addressCountryShortName'] ?? 'BR';

            // Compatibilidade com o formato JSON-LD.
            if ($city === null || $state === null) {
                $jobLocation = $data['jobLocation'] ?? [];
                $address = [];

                if (is_array($jobLocation)) {
                    if (array_is_list($jobLocation)) {
                        $jobLocation = $jobLocation[0] ?? [];
                    }

                    $address = $jobLocation['address'] ?? [];
                }

                if (is_array($address)) {
                    $city = $city ?? $address['addressLocality'] ?? null;
                    $state = $state ?? $address['addressRegion'] ?? null;
                    $country = $country ?? $address['addressCountry'] ?? 'Brasil';

                    if (is_array($country)) {
                        $country = $country['name'] ?? 'Brasil';
                    }

                    if (is_array($state)) {
                        $state = $state['name'] ?? null;
                    }
                }
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
                    $data['jobType'] ?? $data['employmentType'] ?? null
                ),
                'salary' => null,
                'application_url' => $url,
                'external_id' => (string) ($data['id'] ?? $jobId),
                'original_published_at' => $data['publishedAt']
                    ?? $data['datePosted']
                    ?? null,
                'city' => $city,
                'state' => $state,
                'state_code' => $stateCode,
                'country' => $country,
                'country_code' => $countryCode,
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

    private function extractNextData(string $html): array
    {
        $dom = new DOMDocument();

        @$dom->loadHTML($html);

        $node = $dom->getElementById('__NEXT_DATA__');

        if ($node === null) {
            return [];
        }

        $data = json_decode($node->textContent, true);

        return is_array($data) ? $data : [];
    }

    private function isTechnologyVacancy(
        string $title,
        string $description
    ): bool {
        $normalizedTitle = mb_strtolower(
            html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8')
        );
        $normalizedTitle = \Illuminate\Support\Str::ascii($normalizedTitle);
        $normalizedDescription = \Illuminate\Support\Str::ascii(
            mb_strtolower(strip_tags($description))
        );

        // Exclusions are evaluated against the title to avoid rejecting
        // technical jobs merely because their description mentions these topics.
        if (preg_match(
            '/\\b(dados|data|bi|business intelligence|analytics|analise de dados|ciencia de dados|machine learning|aprendizado de maquina|engenharia de dados|data science|crm|erp|sap|abap|salesforce|oracle functional|consultor funcional)\\b/i',
            $normalizedTitle
        ) === 1) {
            return false;
        }

        $technicalTitle = preg_match(
            '/\\b(desenvolvedor|desenvolvedora|developer|programador|programadora|software engineer|engenheiro de software|backend|back-end|frontend|front-end|full.?stack|qa|quality assurance|tester|testes de software|analista de sistemas|administrador de sistemas|analista de suporte|suporte tecnico|service desk|help.?desk|infraestrutura|infra|redes|network|cloud|devops|sre|seguranca da informacao|ciberseguranca|cybersecurity|soc|telecomunicacoes|telecom|arquitet(o|a) de sistemas|especialista de tecnologia|analista de tecnologia|engenheiro de tecnologia)\\b/i',
            $normalizedTitle
        ) === 1;

        if ($technicalTitle) {
            return true;
        }

        $genericTechnologyTitle = preg_match(
            '/\\b(analista|especialista|tecnico|tecnica|engenheiro|engenheira|coordenador|coordenadora)\\b/i',
            $normalizedTitle
        ) === 1;

        return $genericTechnologyTitle && preg_match(
            '/\\b(desenvolvimento de software|desenvolvimento de sistemas|infraestrutura de ti|redes de computadores|administracao de servidores|suporte de ti|suporte tecnico|devops|cloud|ciberseguranca|seguranca da informacao|qa|quality assurance|telecomunicacoes|telecom)\\b/i',
            $normalizedDescription
        ) === 1;
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
        $workplaceType = strtolower(
            (string) ($data['workplaceType'] ?? '')
        );

        if (in_array($workplaceType, ['remote', 'home-office'], true)) {
            return 'remote';
        }

        if (in_array($workplaceType, ['hybrid', 'hybrid-work'], true)) {
            return 'hybrid';
        }

        if (in_array($workplaceType, ['on-site', 'onsite'], true)) {
            return 'onsite';
        }

        // Compatibilidade com JSON-LD.
        $jobLocationType = strtoupper(
            (string) ($data['jobLocationType'] ?? '')
        );

        if ($jobLocationType === 'TELECOMMUTE') {
            return 'remote';
        }

        $description = mb_strtolower(
            strip_tags((string) ($data['description'] ?? ''))
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
            'FULL_TIME',
            'VACANCY_TYPE_EFFECTIVE' => 'full_time',

            'PART_TIME' => 'part_time',

            'CONTRACTOR',
            'VACANCY_TYPE_CONTRACTOR' => 'contract',

            'TEMPORARY' => 'temporary',

            'INTERN',
            'VACANCY_TYPE_INTERNSHIP' => 'internship',

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
