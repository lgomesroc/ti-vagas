<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Services\VacancyLocationFilter;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class TotvsAtsSource implements VacancySource
{
    private readonly VacancyLocationFilter $locationFilter;

    public function __construct(
        private readonly string $companyKey,
        private readonly string $companyName,
        private readonly string $url,
        private readonly ?string $caBundle = null,
        private readonly int $maxJobs = 500,
        ?VacancyLocationFilter $locationFilter = null,
    ) {
        $this->locationFilter = $locationFilter
            ?? new VacancyLocationFilter();
    }

    public function name(): string
    {
        return 'totvs_ats:' . $this->companyKey;
    }

    public function fetch(): iterable
    {
        $client = Http::timeout(60)
            ->withOptions([
                'verify' => $this->caBundle ?: true,
            ])
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; TI-Vagas/1.0)',
                'Accept' => 'text/html,application/xhtml+xml',
            ]);

        $response = $client->get($this->url);
        $response->throw();

        $html = $response->body();

        if (trim($html) === '') {
            throw new RuntimeException(
                "O portal TOTVS ATS de {$this->companyName} retornou HTML vazio."
            );
        }

        $xpath = $this->createXPath($html);

        $cards = $this->extractCards($xpath);

        $vacancies = [];
        $seenIds = [];

        foreach ($cards as $card) {
            if (count($vacancies) >= $this->maxJobs) {
                break;
            }

            $id = $card['id'];

            if ($id === '' || isset($seenIds[$id])) {
                continue;
            }

            $seenIds[$id] = true;

            if ($card['page_url'] === '') {
                continue;
            }

            try {
                $vacancy = $this->fetchVacancyDetails(
                    $client,
                    $card
                );

                if ($vacancy !== null) {
                    $vacancies[] = $vacancy;
                }
            } catch (\Throwable $exception) {
                Log::warning(
                    'Falha ao consultar oportunidade do TOTVS ATS.',
                    [
                        'company' => $this->companyName,
                        'external_id' => $id,
                        'url' => $card['page_url'],
                        'error' => $exception->getMessage(),
                    ]
                );

                continue;
            }
        }

        return $vacancies;
    }

    private function extractCards(DOMXPath $xpath): array
    {
        $nodes = $xpath->query(
            '//div[contains(
                concat(" ", normalize-space(@class), " "),
                " index__job-opportunities__grid__item "
            )]'
        );

        if ($nodes === false) {
            throw new RuntimeException(
                "Não foi possível localizar os cards de {$this->companyName}."
            );
        }

        $cards = [];

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $id = trim($node->getAttribute('data-id'));

            $title = $this->cleanText(
                $node->getAttribute('data-title')
                ?: $node->getAttribute('data-position-name')
            );

            $pageUrl = trim(
                $node->getAttribute('data-page-url')
            );

            if ($title === '' || $pageUrl === '') {
                continue;
            }

            $cards[] = [
                'id' => $id,
                'title' => $title,
                'page_url' => $this->resolveUrl($pageUrl),
                'city' => $this->cleanText(
                    $node->getAttribute('data-city-name')
                ),
                'state' => $this->cleanText(
                    $node->getAttribute('data-state-small-name')
                ),
                'remote' => $this->cleanText(
                    $node->getAttribute('data-remote-string')
                ),
                'department' => $this->cleanText(
                    $node->getAttribute('data-department-name')
                ),
                'position' => $this->cleanText(
                    $node->getAttribute('data-position-name')
                ),
                'code' => $this->cleanText(
                    $node->getAttribute('data-code')
                ),
            ];
        }

        return $cards;
    }

    private function fetchVacancyDetails(
        PendingRequest $client,
        array $card
    ): ?array {
        $response = $client->get($card['page_url']);
        $response->throw();

        $html = $response->body();

        if (trim($html) === '') {
            return null;
        }

        $xpath = $this->createXPath($html);

        $title = $this->extractTitle($xpath)
            ?: $card['title'];

        if ($title === '') {
            return null;
        }

        $subtitle = $this->extractElementText(
            $xpath,
            '//*[contains(
                concat(" ", normalize-space(@class), " "),
                " job-opportunity__subtitle "
            )]'
        );

        $description = $this->extractDataCyText(
            $xpath,
            'desktop-description'
        );

        $responsibilities = $this->extractDataCyText(
            $xpath,
            'desktop-responsibilities'
        );

        $requirements = $this->extractDataCyText(
            $xpath,
            'desktop-requirements'
        );

        $benefits = $this->extractDataCyText(
            $xpath,
            'desktop-benefits'
        );

        $about = $this->extractDataCyText(
            $xpath,
            'desktop-about'
        );

        $descriptionParts = array_filter([
            $description,
            $responsibilities !== ''
                ? "Responsabilidades: {$responsibilities}"
                : '',
            $requirements !== ''
                ? "Requisitos: {$requirements}"
                : '',
            $benefits !== ''
                ? "Benefícios: {$benefits}"
                : '',
            $about !== ''
                ? "Sobre a empresa: {$about}"
                : '',
        ]);

        $fullDescription = implode("\n\n", $descriptionParts);

        $workModel = $this->locationFilter->classifyWorkModel(
            $title,
            implode(' ', [
                $card['city'],
                $card['state'],
                $card['remote'],
                $subtitle,
            ]),
            $fullDescription
        );

        $locationData = $this->extractLocation(
            $card,
            $subtitle,
            $workModel
        );

        $employmentType = $this->extractDataCyText(
            $xpath,
            'desktop-regime'
        );

        $salary = $this->extractDataCyText(
            $xpath,
            'desktop-salary-range'
        );

        $applicationUrl = $this->extractApplicationUrl(
            $xpath
        );

        if ($applicationUrl === null) {
            $applicationUrl = $card['page_url'];
        }

        $publishedAt = $this->extractPublishedAt(
            $xpath,
            $card
        );

        return [
            'title' => $title,
            'company' => $this->companyName,
            'description' => $fullDescription,
            'location' => $locationData['location'],
            'work_model' => $workModel,
            'employment_type' => $this->normalizeEmploymentType(
                $employmentType
            ),
            'salary' => $this->normalizeSalary($salary),
            'application_url' => $applicationUrl,
            'external_id' => $this->name() . ':' . $card['id'],
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

    private function extractTitle(DOMXPath $xpath): string
    {
        $title = $this->extractElementText(
            $xpath,
            '//*[contains(
                concat(" ", normalize-space(@class), " "),
                " job-opportunity__title "
            )]'
        );

        if ($title !== '') {
            return $title;
        }

        $meta = $xpath->query(
            '//meta[@property="og:title"]/@content'
        );

        if ($meta !== false && $meta->length > 0) {
            return $this->cleanText(
                $meta->item(0)?->nodeValue ?? ''
            );
        }

        return '';
    }

    private function extractDataCyText(
        DOMXPath $xpath,
        string $dataCy
    ): string {
        $nodes = $xpath->query(
            '//*[@data-cy="' . $dataCy . '"]'
        );

        if ($nodes === false || $nodes->length === 0) {
            return '';
        }

        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $text = $this->cleanText(
                    $node->textContent
                );

                if ($text !== '') {
                    return $text;
                }
            }
        }

        return '';
    }

    private function extractElementText(
        DOMXPath $xpath,
        string $query
    ): string {
        $nodes = $xpath->query($query);

        if ($nodes === false || $nodes->length === 0) {
            return '';
        }

        return $this->cleanText(
            $nodes->item(0)?->textContent ?? ''
        );
    }

    private function extractApplicationUrl(
        DOMXPath $xpath
    ): ?string {
        $nodes = $xpath->query(
            '//*[@data-cy="desktop-apply"]'
        );

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $href = trim(
                $node->getAttribute('href')
            );

            if ($href === '') {
                $anchors = $xpath->query(
                    './/a[@href]',
                    $node
                );

                if ($anchors !== false && $anchors->length > 0) {
                    $href = trim(
                        $anchors->item(0)?->attributes?->getNamedItem('href')?->nodeValue ?? ''
                    );
                }
            }

            if ($href !== '') {
                return $this->resolveUrl($href);
            }
        }

        return null;
    }

    private function extractLocation(
        array $card,
        string $subtitle,
        ?string $workModel
    ): array {
        $city = $card['city'] !== ''
            ? $card['city']
            : null;

        $state = $card['state'] !== ''
            ? $card['state']
            : null;

        /*
         * Algumas páginas informam a modalidade e a localidade
         * somente no subtítulo. Não interpretar o nome da empresa
         * como cidade.
         */
        if ($city === null && $state === null) {
            $parts = preg_split(
                '/\s*\|\s*/',
                $subtitle
            ) ?: [];

            foreach (array_slice($parts, 1) as $part) {
                $part = $this->cleanText($part);

                if ($part === '') {
                    continue;
                }

                if ($this->locationFilter->classifyWorkModel(
                    null,
                    $part,
                    null
                ) !== null) {
                    continue;
                }

                if (preg_match(
                    '/^[\p{L}\s.-]+,\s*[A-Z]{2}$/u',
                    $part
                )) {
                    $locationParts = explode(',', $part, 2);
                    $city = trim($locationParts[0]);
                    $state = trim($locationParts[1]);
                    break;
                }

                if ($this->looksLikeBrazilianState($part)) {
                    $state = $part;
                    break;
                }
            }
        }

        if ($workModel === 'Remoto') {
            return [
                'location' => 'Brasil',
                'city' => null,
                'state' => null,
                'state_code' => null,
                'country' => 'Brasil',
                'country_code' => 'BR',
            ];
        }

        $parts = array_filter([
            $city,
            $state,
            ($city !== null || $state !== null)
                ? 'Brasil'
                : null,
        ]);

        return [
            'location' => $parts !== []
                ? implode(', ', $parts)
                : null,
            'city' => $city,
            'state' => $state,
            'state_code' => $state !== null
                && preg_match('/^[A-Z]{2}$/', $state)
                    ? $state
                    : null,
            'country' => 'Brasil',
            'country_code' => 'BR',
        ];
    }

    private function looksLikeBrazilianState(string $value): bool
    {
        return in_array(
            Str::ascii(mb_strtolower(trim($value))),
            [
                'acre',
                'alagoas',
                'amapa',
                'amazonas',
                'bahia',
                'ceara',
                'distrito federal',
                'espirito santo',
                'goias',
                'maranhao',
                'mato grosso',
                'mato grosso do sul',
                'minas gerais',
                'para',
                'paraiba',
                'parana',
                'pernambuco',
                'piaui',
                'rio de janeiro',
                'rio grande do norte',
                'rio grande do sul',
                'rondonia',
                'roraima',
                'santa catarina',
                'sao paulo',
                'sergipe',
                'tocantins',
            ],
            true
        );
    }

    private function normalizeEmploymentType(
        string $value
    ): ?string {
        $value = $this->cleanText($value);

        if ($value === '') {
            return null;
        }

        return match (Str::ascii(mb_strtolower($value))) {
            'clt' => 'CLT',
            'pj' => 'PJ',
            'pessoa juridica' => 'PJ',
            'estagio' => 'Estágio',
            'temporario' => 'Temporário',
            'tempo integral' => 'Tempo integral',
            'meio periodo' => 'Meio período',
            default => $value,
        };
    }

    private function normalizeSalary(
        string $salary
    ): ?string {
        $salary = $this->cleanText($salary);

        if ($salary === '') {
            return null;
        }

        return $salary;
    }

    private function extractPublishedAt(
        DOMXPath $xpath,
        array $card
    ): ?string {
        $metaQueries = [
            '//meta[@property="article:published_time"]/@content',
            '//meta[@name="datePosted"]/@content',
            '//meta[@property="datePublished"]/@content',
        ];

        foreach ($metaQueries as $query) {
            $nodes = $xpath->query($query);

            if ($nodes !== false && $nodes->length > 0) {
                $date = $this->normalizeDate(
                    $nodes->item(0)?->nodeValue
                );

                if ($date !== null) {
                    return $date;
                }
            }
        }

        $timeNodes = $xpath->query('//time[@datetime]/@datetime');

        if ($timeNodes !== false) {
            foreach ($timeNodes as $node) {
                $date = $this->normalizeDate(
                    $node->nodeValue
                );

                if ($date !== null) {
                    return $date;
                }
            }
        }

        foreach ([
            'data-published-at',
            'data-publication-date',
            'data-created-at',
        ] as $attribute) {
            if (!empty($card[$attribute])) {
                $date = $this->normalizeDate(
                    $card[$attribute]
                );

                if ($date !== null) {
                    return $date;
                }
            }
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

    private function resolveUrl(string $path): string
    {
        $path = trim($path);

        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }

        $parts = parse_url($this->url);

        if (
            !isset($parts['scheme'])
            || !isset($parts['host'])
        ) {
            throw new RuntimeException(
                "URL base inválida para {$this->companyName}."
            );
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];

        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin . '/' . ltrim($path, '/');
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
                    "Não foi possível interpretar o HTML de {$this->companyName}."
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
