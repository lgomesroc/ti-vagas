<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Services\VacancyLocationFilter;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PandapeSource implements VacancySource
{
    private readonly VacancyLocationFilter $locationFilter;

    private const TECHNOLOGY_TERMS = [
        // Desenvolvimento.
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
        'integration developer',
        'php developer',
        '.net developer',
        'java developer',
        'python developer',
        'desenvolvedor de software',
        'desenvolvedor de sistemas',
        'desenvolvedor web',
        'desenvolvedor mobile',
        'desenvolvedor de aplicativos',
        'software developer',
        'web developer',
        'mobile developer',
        'application developer',
        'analista de desenvolvimento',
        'analista de sistemas de informacao',
        'ios developer',
        'android developer',

        // QA e testes.
        'qa',
        'quality assurance',
        'quality analyst',
        'analista de qa',
        'analista de testes',
        'tester',
        'software tester',
        'test engineer',
        'engenheiro de testes',
        'automation tester',
        'qa automation',

        // Suporte.
        'suporte tecnico',
        'suporte de ti',
        'suporte ti',
        'analista de suporte',
        'tecnico de informatica',
        'tecnico de suporte',
        'help desk',
        'helpdesk',
        'service desk',
        'servicedesk',
        'technical support',
        'it support',
        'field support',
        'analista de service desk',
        'analista de help desk',
        'suporte ao usuario',
        'suporte de sistemas',
        'suporte a sistemas',
        'suporte de informatica',
        'assistente de suporte',
        'auxiliar de suporte',
        'analista de suporte de sistemas',
        'analista de suporte tecnico',
        'tecnico de suporte ti',
        'tecnico de service desk',
        'operador de service desk',
        'field service',
        'microinformatica',

        // Infraestrutura, redes, cloud e segurança.
        'infraestrutura',
        'infrastructure',
        'analista de redes',
        'tecnico de redes',
        'network engineer',
        'network analyst',
        'cloud engineer',
        'devops',
        'sysadmin',
        'system administrator',
        'administrador de sistemas',
        'analista de sistemas',
        'noc',
        'analista de infraestrutura',
        'tecnico de infraestrutura',
        'assistente de infraestrutura',
        'auxiliar de infraestrutura',
        'administrador de redes',
        'administrador de banco de dados',
        'database administrator',
        'dba',
        'site reliability engineer',
        'sre',
        'network administrator',
        'cloud administrator',
        'analista de cloud',
        'engenheiro de infraestrutura',
        'security analyst',
        'analista de seguranca da informacao',
        'analista de seguranca',
        'cybersecurity',
        'ciberseguranca',
        'soc analyst',
    ];

    private const EXCLUDED_TITLE_TERMS = [
        // Senioridade e programas fora do escopo.
        'pleno',
        'senior',
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
        'supervisor',
        'supervisora',
        'trainee',
        'estagio',
        'estagiario',
        'intern',
        'internship',

        // Áreas administrativas e comerciais.
        'marketing',
        'vendas',
        'sales',
        'juridico',
        'legal',
        'financeiro',
        'financial',
        'recruiter',
        'recrutamento',
        'recursos humanos',
        'human resources',
        'analista administrativo',
        'analista de gestao',
        'analista de operacoes',
        'compras',
        'procurement',
        'contabilidade',
        'accounting',
        'logistica',
        'logistics',
        'comercial',
        'customer success',
        'customer experience',
        'atendimento ao cliente',

        // CRM, ERP e sistemas corporativos especializados.
        'crm',
        'erp',
        'sap',
        'abap',
        'totvs',
        'protheus',
        'consultor funcional',
        'analista funcional',
        'implantacao de sistemas',
        'implantador de sistemas',

        // Dados e inteligência artificial.
        'data analyst',
        'data analytics',
        'analytics engineer',
        'analista de dados',
        'engenheiro de dados',
        'data engineer',
        'cientista de dados',
        'data scientist',
        'business intelligence',
        'business intelligence analyst',
        'bi analyst',
        'analista de bi',
        'machine learning',
        'deep learning',
        'ml engineer',
        'ai engineer',
        'engenheiro de inteligencia artificial',
        'especialista em inteligencia artificial',
    ];

    public function __construct(
        private readonly string $companyKey,
        private readonly string $companyName,
        private readonly string $baseUrl,
        private readonly ?string $caBundle = null,
        private readonly int $maxPages = 100,
        ?VacancyLocationFilter $locationFilter = null,
    ) {
        $this->locationFilter = $locationFilter
            ?? new VacancyLocationFilter();
    }

    public function name(): string
    {
        return 'pandape:' . $this->companyKey;
    }

    public function fetch(): iterable
    {
        $cookieJar = new CookieJar();

        $listingClient = Http::timeout(60)
            ->withOptions([
                'cookies' => $cookieJar,
                'verify' => $this->caBundle ?: true,
            ])
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; TI-Vagas/1.0)',
                'Accept' => 'text/html,application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ]);

        // Cliente separado para detalhes, compartilhando os mesmos cookies.
        $detailClient = Http::timeout(30)
            ->withOptions([
                'cookies' => $cookieJar,
                'verify' => $this->caBundle ?: true,
            ])
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; TI-Vagas/1.0)',
                'Accept' => 'text/html',
            ]);

        $homeResponse = $listingClient->get($this->baseUrl);

        $homeResponse->throw();

        if (trim($homeResponse->body()) === '') {
            throw new RuntimeException(
                "O portal Pandapé de {$this->companyName} retornou HTML vazio."
            );
        }

        $vacancies = [];
        $seenIds = [];

        for ($page = 1; $page <= $this->maxPages; $page++) {
            $response = $listingClient
                ->asForm()
                ->post(
                    rtrim($this->baseUrl, '/') . '/ListVacancies',
                    [
                        'PageNumber' => $page,
                        'PageSize' => 20,
                    ]
                );

            $response->throw();

            $data = $response->json();

            if (
                !is_array($data)
                || !isset($data['view'])
                || !is_string($data['view'])
            ) {
                throw new RuntimeException(
                    "Resposta inválida na página {$page} do Pandapé ({$this->companyName})."
                );
            }

            $html = trim($data['view']);

            if ($html === '') {
                break;
            }

            $cards = $this->extractCards($html);

            foreach ($cards as $card) {
                $id = $card['id'];

                if (isset($seenIds[$id])) {
                    continue;
                }

                $seenIds[$id] = true;

                // Evita consultar detalhes de anúncios fora do escopo.
                if (!$this->isPotentialTechnologyRole($card['text'])) {
                    continue;
                }

                $vacancy = $this->fetchVacancyDetails(
                    $detailClient,
                    $id,
                    $card
                );

                if ($vacancy !== null) {
                    $vacancies[] = $vacancy;
                }
            }

            $isLast = filter_var(
                $data['isLast'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            );

            if ($isLast) {
                break;
            }

            /*
             * O limite de páginas é um limite operacional.
             * Atingi-lo encerra a coleta parcial normalmente.
             */
            if ($page === $this->maxPages) {
                break;
            }
        }

        return $vacancies;
    }

    private function isPotentialTechnologyRole(string $title): bool
    {
        $normalizedTitle = $this->normalize($title);

        if ($normalizedTitle === '') {
            return false;
        }

        if ($this->containsAny(
            $normalizedTitle,
            self::EXCLUDED_TITLE_TERMS
        )) {
            return false;
        }

        return $this->containsAny(
            $normalizedTitle,
            self::TECHNOLOGY_TERMS
        );
    }

    private function containsAny(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            $normalizedTerm = $this->normalize($term);

            if (preg_match(
                '/(^|[^a-z0-9])'
                . preg_quote($normalizedTerm, '/')
                . '([^a-z0-9]|$)/',
                $text
            )) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $text): string
    {
        return \Illuminate\Support\Str::ascii(
            mb_strtolower($text)
        );
    }

    private function extractCards(string $html): array
    {
        $xpath = $this->createXPath($html);

        $nodes = $xpath->query(
            '//a[contains(
                concat(" ", normalize-space(@class), " "),
                " card-vacancy "
            )]'
        );

        if ($nodes === false) {
            throw new RuntimeException(
                'Não foi possível localizar os cards de vagas do Pandapé.'
            );
        }

        $cards = [];

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $href = trim($node->getAttribute('href'));

            if (!preg_match('~/Detail/(\d+)~i', $href, $matches)) {
                continue;
            }

            $cards[] = [
                'id' => $matches[1],
                'text' => $this->cleanText($node->textContent),
            ];
        }

        return $cards;
    }

    private function fetchVacancyDetails(
        PendingRequest $client,
        string $id,
        array $card
    ): ?array {
        $url = rtrim($this->baseUrl, '/')
            . '/Detail/'
            . $id;

        $response = $client->get($url);

        $response->throw();

        $xpath = $this->createXPath($response->body());

        $scripts = $xpath->query(
            '//script[@type="application/ld+json"]'
        );

        if ($scripts === false) {
            return null;
        }

        $job = null;

        foreach ($scripts as $script) {
            if (!$script instanceof DOMElement) {
                continue;
            }

            $json = json_decode(
                $script->textContent,
                true
            );

            if (!is_array($json)) {
                continue;
            }

            $job = $this->findJobPosting($json);

            if ($job !== null) {
                break;
            }
        }

        if ($job === null) {
            return null;
        }

        $title = $this->cleanText(
            (string) ($job['title'] ?? '')
        );

        $description = $this->cleanText(
            strip_tags(
                html_entity_decode(
                    (string) ($job['description'] ?? ''),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                )
            )
        );

        if (
            $title === ''
            || $description === ''
            || !$this->isPotentialTechnologyRole($title)
        ) {
            return null;
        }

        $publishedAt = $this->normalizeDate(
            $job['datePosted'] ?? null
        );

        // Não substituir a data original pela data da coleta.
        if ($publishedAt === null) {
            return null;
        }

        $locationData = $this->extractLocation(
            $job['jobLocation'] ?? null
        );

        $location = $locationData['location'];

        $workModel = $this->extractWorkModel(
            $card['text']
        );

        if (!$this->locationFilter->isEligibleForCollection(
            $location ?? '',
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
            'location' => $location,
            'work_model' => $workModel,
            'employment_type' => $this->normalizeEmploymentType(
                $job['employmentType'] ?? null
            ),
            'salary' => $this->extractSalary($job, $description),
            'application_url' => $url,
            'external_id' => $this->name() . ':' . $id,
            'original_published_at' => $publishedAt,

            'city' => $locationData['city'],
            'state' => $locationData['state'],
            'state_code' => $locationData['state_code'],
            'country' => $locationData['country'],
            'country_code' => $locationData['country_code'],
            'latitude' => null,
            'longitude' => null,
        ];
    }

    private function findJobPosting(array $data): ?array
    {
        $type = $data['@type'] ?? null;

        if ($type === 'JobPosting') {
            return $data;
        }

        if (
            is_array($type)
            && in_array('JobPosting', $type, true)
        ) {
            return $data;
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $found = $this->findJobPosting($value);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function extractLocation(mixed $data): array
    {
        $empty = [
            'location' => null,
            'city' => null,
            'state' => null,
            'state_code' => null,
            'country' => null,
            'country_code' => null,
        ];

        if (!is_array($data)) {
            return $empty;
        }

        if (array_is_list($data)) {
            $data = $data[0] ?? [];
        }

        if (!is_array($data)) {
            return $empty;
        }

        $address = $data['address'] ?? [];

        if (!is_array($address)) {
            return $empty;
        }

        $city = $address['addressLocality'] ?? null;
        $state = $address['addressRegion'] ?? null;
        $countryData = $address['addressCountry'] ?? null;

        $country = is_array($countryData)
            ? ($countryData['name'] ?? null)
            : $countryData;

        $countryCode = is_array($countryData)
            ? ($countryData['identifier'] ?? null)
            : null;

        $parts = array_filter([
            $city,
            $state,
            $country,
        ]);

        return [
            'location' => $parts !== []
                ? implode(', ', $parts)
                : null,
            'city' => $city,
            'state' => $state,
            'state_code' => null,
            'country' => $country,
            'country_code' => $countryCode,
        ];
    }

    private function extractWorkModel(string $text): ?string
    {
        if (preg_match('/\bremoto\b/iu', $text)) {
            return 'Remoto';
        }

        if (preg_match('/\bh[ií]brido\b/iu', $text)) {
            return 'Híbrido';
        }

        if (preg_match('/\bpresencial\b/iu', $text)) {
            return 'Presencial';
        }

        return null;
    }

    private function normalizeEmploymentType(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return match (strtolower(trim($value))) {
            'full-time' => 'Tempo integral',
            'part-time' => 'Meio período',
            'contractor' => 'Contrato',
            'temporary' => 'Temporário',
            'internship' => 'Estágio',
            default => trim($value),
        };
    }

    private function extractSalary(
        array $job,
        string $description
    ): ?string {
        $salary = $job['baseSalary'] ?? null;

        if (is_array($salary)) {
            $value = $salary['value'] ?? null;

            if (is_array($value)) {
                $min = $value['minValue'] ?? null;
                $max = $value['maxValue'] ?? null;

                if ($min !== null && $max !== null) {
                    return "{$min} - {$max}";
                }

                if ($min !== null || $max !== null) {
                    return (string) ($min ?? $max);
                }
            }

            if (is_numeric($value)) {
                return (string) $value;
            }
        }

        if (preg_match(
            '/sal[aá]rio\s*[:\-]?\s*(R\$\s*[\d.]+(?:,\d{2})?(?:\s*(?:a|até)\s*R\$\s*[\d.]+(?:,\d{2})?)?)/iu',
            $description,
            $matches
        )) {
            return trim($matches[1]);
        }

        return null;
    }

    private function normalizeDate(mixed $date): ?string
    {
        if (!is_string($date) || trim($date) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(
                $date,
                'UTC'
            )->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function createXPath(string $html): DOMXPath
    {
        $previousState = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument('1.0', 'UTF-8');

            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8">' . $html,
                LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR
            );

            if (!$loaded) {
                throw new RuntimeException(
                    'Não foi possível interpretar o HTML do Pandapé.'
                );
            }

            return new DOMXPath($document);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousState);
        }
    }

    private function cleanText(string $text): string
    {
        $text = html_entity_decode(
            $text,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }
}
