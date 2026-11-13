<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Services\VacancyLocationFilter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class JobConvoSource implements VacancySource
{
    private readonly VacancyLocationFilter $locationFilter;

    private const TECHNOLOGY_TERMS = [
        'desenvolvedor',
        'developer',
        'programador',
        'software engineer',
        'engenheiro de software',
        'backend',
        'back-end',
        'frontend',
        'front-end',
        'full stack',
        'fullstack',
        'php',
        '.net',
        'java',
        'python',
        'javascript',
        'typescript',
        'analista de sistemas',
        'analista de desenvolvimento',
        'suporte tecnico',
        'suporte de ti',
        'analista de suporte',
        'tecnico de suporte',
        'help desk',
        'helpdesk',
        'service desk',
        'infraestrutura',
        'infrastructure',
        'analista de redes',
        'tecnico de redes',
        'network engineer',
        'cloud engineer',
        'devops',
        'sysadmin',
        'system administrator',
        'administrador de sistemas',
        'analista de infraestrutura',
        'tecnico de infraestrutura',
        'noc',
        'qa',
        'quality assurance',
        'analista de testes',
        'software tester',
        'test engineer',
        'security analyst',
        'ciberseguranca',
    ];

    private const EXCLUDED_TERMS = [
        'pleno',
        'senior',
        'sênior',
        'staff',
        'principal',
        'lead',
        'manager',
        'gerente',
        'director',
        'diretor',
        'head',
        'architect',
        'arquiteto',
        'especialista',
        'coordenador',
        'coordenadora',
        'lider',
        'líder',
        'supervisor',
        'supervisora',
        'trainee',
        'estagio',
        'estágio',
        'estagiario',
        'estagiário',
        'intern',
        'internship',
        'marketing',
        'vendas',
        'sales',
        'juridico',
        'jurídico',
        'financeiro',
        'financial',
        'recursos humanos',
        'human resources',
        'analista administrativo',
        'analista de operacoes',
        'analista de operações',
        'compras',
        'procurement',
        'contabilidade',
        'accounting',
        'logistica',
        'logística',
        'comercial',
        'customer success',
        'customer experience',
        'atendimento ao cliente',
        'crm',
        'erp',
        'sap',
        'abap',
        'protheus',
        'data analyst',
        'data engineer',
        'analista de dados',
        'engenheiro de dados',
        'cientista de dados',
        'data scientist',
        'business intelligence',
        'machine learning',
        'deep learning',
    ];

    private const JUNIOR_TERMS = [
        'junior',
        'júnior',
        'jr',
        'jr.',
        'entry level',
        'entry-level',
        'iniciante',
    ];

    public function __construct(
        private readonly string $companyKey,
        private readonly string $companyName,
        private readonly string $baseUrl,
        private readonly string $careerPageId,
        private readonly ?string $caBundle = null,
        private readonly int $maxPages = 110,
        ?VacancyLocationFilter $locationFilter = null,
    ) {
        $this->locationFilter = $locationFilter
            ?? new VacancyLocationFilter();
    }

    public function name(): string
    {
        return 'jobconvo:' . $this->companyKey;
    }

    public function fetch(): iterable
    {
        $client = Http::timeout(60)
            ->withOptions([
                'verify' => $this->caBundle ?: true,
            ])
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; TI-Vagas/1.0)',
                'Accept' => 'text/html,application/json',
            ]);

        $vacancies = [];
        $seenIds = [];
        $seenPages = [];

        for ($page = 1; $page <= $this->maxPages; $page++) {
            $response = $client->get($this->baseUrl, [
                'page' => $page,
            ]);

            $response->throw();

            $html = $response->body();

            if (trim($html) === '') {
                if ($page === 1) {
                    throw new RuntimeException(
                        "O portal JobConvo de {$this->companyName} retornou HTML vazio."
                    );
                }

                break;
            }

            $jobs = $this->extractJobLinks($html);

            if ($jobs === []) {
                break;
            }

            $pageSignature = hash(
                'sha256',
                implode('|', array_column($jobs, 'uuid'))
            );

            if (isset($seenPages[$pageSignature])) {
                break;
            }

            $seenPages[$pageSignature] = true;

            foreach ($jobs as $job) {
                $uuid = $job['uuid'];

                if ($uuid === '' || isset($seenIds[$uuid])) {
                    continue;
                }

                $seenIds[$uuid] = true;

                if (!$this->isTechnologyRole($job['title'])) {
                    continue;
                }

                try {
                    $vacancy = $this->fetchJobDetails(
                        $client,
                        $job
                    );

                    if ($vacancy !== null) {
                        $vacancies[] = $vacancy;
                    }
                } catch (\Throwable $exception) {
                    Log::warning(
                        'Falha ao consultar vaga do JobConvo.',
                        [
                            'company' => $this->companyName,
                            'uuid' => $uuid,
                            'url' => $job['url'],
                            'error' => $exception->getMessage(),
                        ]
                    );

                    continue;
                }
            }
        }

        return $vacancies;
    }

    private function extractJobLinks(string $html): array
    {
        $previousState = libxml_use_internal_errors(true);

        try {
            $document = new \DOMDocument('1.0', 'UTF-8');

            $document->loadHTML(
                '<?xml encoding="UTF-8">' . $html,
                LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR
            );

            $xpath = new \DOMXPath($document);

            $nodes = $xpath->query('//a[@href]');

            if ($nodes === false) {
                return [];
            }

            $jobs = [];

            foreach ($nodes as $node) {
                $href = trim(
                    $node->attributes?->getNamedItem('href')?->nodeValue ?? ''
                );

                if (!preg_match(
                    '~^/job/([^/]+)/([0-9a-f-]{36})/?~i',
                    $href,
                    $matches
                )) {
                    continue;
                }

                $slug = $matches[1];
                $uuid = $matches[2];

                $title = $this->cleanText(
                    $node->textContent
                );

                if ($title === '') {
                    $title = $this->titleFromSlug($slug);
                }

                if ($title === '') {
                    continue;
                }

                $jobs[$uuid] = [
                    'uuid' => $uuid,
                    'slug' => $slug,
                    'title' => $title,
                    'url' => $this->resolveUrl($href),
                ];
            }

            return array_values($jobs);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousState);
        }
    }

    private function fetchJobDetails(
        $client,
        array $job
    ): ?array {
        $apiUrl = rtrim($this->baseUrl, '/');

        $parts = parse_url($apiUrl);

        if (
            !isset($parts['scheme'])
            || !isset($parts['host'])
        ) {
            throw new RuntimeException(
                "URL base inválida para {$this->companyName}."
            );
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];

        $endpoint = $origin
            . '/pt-br/api/job/'
            . rawurlencode($job['uuid'])
            . '/'
            . $job['slug']
            . '/';

        $response = $client
            ->acceptJson()
            ->get($endpoint);

        $response->throw();

        $data = $response->json();

        if (!is_array($data)) {
            return null;
        }

        if (
            !isset($data['id'])
            || !isset($data['title'])
            || !isset($data['description'])
        ) {
            return null;
        }

        if (
            isset($data['status'])
            && !in_array((string) $data['status'], ['1'], true)
        ) {
            return null;
        }

        $title = $this->cleanText($data['title']);
        $description = $this->buildDescription($data);

        if ($title === '' || $description === '') {
            return null;
        }

        if ($this->containsAny($title, self::EXCLUDED_TERMS)) {
            return null;
        }

        if (!$this->containsAny(
            $title . ' ' . $description,
            self::JUNIOR_TERMS
        )) {
            return null;
        }

        $publishedAt = $this->normalizeDate(
            $data['pub_date'] ?? null
        );

        if ($publishedAt === null) {
            return null;
        }

        $city = $this->cleanText($data['city'] ?? '');
        $state = $this->cleanText($data['state'] ?? '');
        $country = $this->cleanText($data['country'] ?? '');

        $workModel = $this->normalizeWorkModel(
            $data['type_work_location'] ?? null
        );

        if ($workModel === 'Remoto') {
            $location = 'Brasil';
            $city = '';
            $state = '';
            $country = 'Brasil';
        } else {
            $location = implode(', ', array_filter([
                $city,
                $state,
                $country,
            ]));
        }

        if (!$this->locationFilter->isEligibleForCollection(
            $location,
            $title,
            $description,
            $workModel
        )) {
            return null;
        }

        return [
            'title' => $title,
            'company' => $this->companyName,
            'description' => $description,
            'location' => $location !== '' ? $location : null,
            'work_model' => $workModel,
            'employment_type' => $this->normalizeEmployment(
                $data['employment'] ?? null
            ),
            'salary' => $this->normalizeSalary(
                $data['salary'] ?? null
            ),
            'application_url' => $job['url'],
            'external_id' => $this->name() . ':' . $job['uuid'],
            'original_published_at' => $publishedAt,

            'city' => $city !== '' ? $city : null,
            'state' => $state !== '' ? $state : null,
            'state_code' => $this->normalizeStateCode($state),
            'country' => $country !== '' ? $country : null,
            'country_code' => $this->normalizeCountryCode($country),
            'latitude' => null,
            'longitude' => null,
        ];
    }

    private function buildDescription(array $data): string
    {
        $parts = [];

        foreach ([
            'description' => 'Descrição',
            'requirements' => 'Requisitos',
            'benefits' => 'Benefícios',
            'annotation' => 'Informações adicionais',
            'company_about' => 'Sobre a empresa',
        ] as $field => $label) {
            $content = $this->cleanDescription(
                $data[$field] ?? ''
            );

            if ($content !== '') {
                $parts[] = $label . ":\n" . $content;
            }
        }

        return implode("\n\n", $parts);
    }

    private function isTechnologyRole(string $title): bool
    {
        $normalized = $this->normalize($title);

        if ($normalized === '') {
            return false;
        }

        if ($this->containsAny(
            $normalized,
            self::EXCLUDED_TERMS
        )) {
            return false;
        }

        return $this->containsAny(
            $normalized,
            self::TECHNOLOGY_TERMS
        );
    }

    private function normalizeWorkModel(mixed $value): ?string
    {
        return match ((string) $value) {
            '0' => 'Presencial',
            '1' => 'Híbrido',
            '2' => 'Remoto',
            default => null,
        };
    }

    private function normalizeEmployment(mixed $value): ?string
    {
        $value = $this->cleanText($value);

        if ($value === '') {
            return null;
        }

        $normalized = $this->normalize($value);

        return match (true) {
            str_contains($normalized, 'normal') => 'Tempo integral',
            str_contains($normalized, 'temporario') => 'Temporário',
            str_contains($normalized, 'estagio') => 'Estágio',
            str_contains($normalized, 'pj') => 'PJ',
            default => $value,
        };
    }

    private function normalizeSalary(mixed $value): ?string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $salary = $this->cleanText((string) $value);

        return $salary !== '' ? $salary : null;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                return CarbonImmutable::createFromTimestamp(
                    (int) $value,
                    'UTC'
                )->toIso8601String();
            }

            return CarbonImmutable::parse(
                $value,
                'UTC'
            )->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeStateCode(string $state): ?string
    {
        $states = [
            'Acre' => 'AC',
            'Alagoas' => 'AL',
            'Amapá' => 'AP',
            'Amazonas' => 'AM',
            'Bahia' => 'BA',
            'Ceará' => 'CE',
            'Distrito Federal' => 'DF',
            'Espírito Santo' => 'ES',
            'Goiás' => 'GO',
            'Maranhão' => 'MA',
            'Mato Grosso' => 'MT',
            'Mato Grosso do Sul' => 'MS',
            'Minas Gerais' => 'MG',
            'Pará' => 'PA',
            'Paraíba' => 'PB',
            'Paraná' => 'PR',
            'Pernambuco' => 'PE',
            'Piauí' => 'PI',
            'Rio de Janeiro' => 'RJ',
            'Rio Grande do Norte' => 'RN',
            'Rio Grande do Sul' => 'RS',
            'Rondônia' => 'RO',
            'Roraima' => 'RR',
            'Santa Catarina' => 'SC',
            'São Paulo' => 'SP',
            'Sergipe' => 'SE',
            'Tocantins' => 'TO',
        ];

        foreach ($states as $name => $code) {
            if ($this->normalize($name) === $this->normalize($state)) {
                return $code;
            }
        }

        return preg_match('/^[A-Z]{2}$/i', $state)
            ? strtoupper($state)
            : null;
    }

    private function normalizeCountryCode(string $country): ?string
    {
        return in_array(
            $this->normalize($country),
            ['brasil', 'brazil', 'br'],
            true
        ) ? 'BR' : null;
    }

    private function titleFromSlug(string $slug): string
    {
        $slug = preg_replace('/^[A-Za-z0-9_-]+-/', '', $slug);

        return $this->cleanText(
            str_replace('-', ' ', $slug)
        );
    }

    private function cleanDescription(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = html_entity_decode(
            $value,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $value = preg_replace(
            '/<\s*br\s*\/?>|<\/\s*p\s*>|<\/\s*div\s*>|<\/\s*h[1-6]\s*>/i',
            "\n",
            $value
        );

        $value = preg_replace(
            '/<\s*li\b[^>]*>/i',
            "\n- ",
            $value
        );

        $value = strip_tags($value);
        $value = html_entity_decode(
            $value,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

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

        $value = html_entity_decode(
            strip_tags((string) $value),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        return trim(
            preg_replace('/\s+/u', ' ', $value)
        );
    }

    private function normalize(string $value): string
    {
        return Str::ascii(
            mb_strtolower($value)
        );
    }

    private function containsAny(string $text, array $terms): bool
    {
        $normalizedText = $this->normalize($text);

        foreach ($terms as $term) {
            $normalizedTerm = $this->normalize($term);

            if (preg_match(
                '/(^|[^a-z0-9])'
                . preg_quote($normalizedTerm, '/')
                . '([^a-z0-9]|$)/',
                $normalizedText
            )) {
                return true;
            }
        }

        return false;
    }

    private function resolveUrl(string $path): string
    {
        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }

        $parts = parse_url($this->baseUrl);

        return $parts['scheme'] . '://'
            . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . '/'
            . ltrim($path, '/');
    }
}