<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Services\VacancyLocationFilter;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class RadancySource implements VacancySource
{
    private readonly VacancyLocationFilter $locationFilter;

    public function __construct(
        private readonly string $companyKey,
        private readonly string $companyName,
        private readonly string $url,
        private readonly ?string $caBundle = null,
        private readonly int $maxPages = 10,
        private readonly int $maxJobs = 200,
        private readonly array $countries = ['Brazil'],
        private readonly string $technologyFacetName = 'Professional Field',
        private readonly string $technologyFacetValue = 'Tech & Data',
        ?VacancyLocationFilter $locationFilter = null,
    ) {
        $this->locationFilter = $locationFilter ?? new VacancyLocationFilter();
    }

    public function name(): string
    {
        return 'radancy:' . $this->companyKey;
    }

    public function fetch(): iterable
    {
        $listUrl = $this->resolveListUrl();
        $jobs = [];
        $seen = [];

        // Sweep 1: all vacancies for each configured country. This is the
        // authoritative inventory; do not restrict it to the employer's
        // category because relevant IT roles can be misclassified upstream.
        foreach ($this->countries as $country) {
            $this->collectSearch($listUrl, ['Location' => $country], $jobs, $seen);
        }

        // Sweep 2: the employer's official Tech & Data facet for each country.
        // Merge it with the country inventory to protect against inconsistent
        // location/category indexing and retain provenance for editorial review.
        foreach ($this->countries as $country) {
            $this->collectSearch($listUrl, [
                'Location' => $country,
                'CustomFacetName' => $this->technologyFacetName,
                'FacetTerm' => $this->technologyFacetValue,
                'FacetType' => '0',
            ], $jobs, $seen);
        }

        return array_slice($jobs, 0, max(1, $this->maxJobs));
    }

    private function collectSearch(string $listUrl, array $filters, array &$jobs, array &$seen): void
    {
        $origin = parse_url($listUrl, PHP_URL_SCHEME) . '://' . parse_url($listUrl, PHP_URL_HOST);
        $page = 1;
        $lastPage = max(1, min($this->maxPages, 50));

        do {
            $query = array_merge([
                'ActiveFacetID' => '0',
                'CurrentPage' => (string) $page,
                'RecordsPerPage' => '100',
                'Distance' => '50',
                'RadiusUnitType' => '0',
                'Keywords' => '',
                'Location' => '',
                'ShowRadius' => 'False',
                'IsPagination' => 'True',
                'CustomFacetName' => '',
                'FacetTerm' => '',
                'FacetType' => '0',
                'SearchResultsModuleName' => 'Search Results',
                'SortCriteria' => '0',
                'SortDirection' => '0',
                'SearchType' => '5',
                '_' => (string) Str::uuid(),
            ], $filters);

            $response = Http::timeout(45)
                ->withOptions(['verify' => $this->caBundle ?: true])
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; TI-Vagas/1.0)',
                    'Accept' => 'application/json',
                    'X-Requested-With' => 'XMLHttpRequest',
                ])
                ->get($listUrl . '/results', $query);

            $response->throw();
            $payload = $response->json();

            if (!is_array($payload) || !isset($payload['results']) || !is_string($payload['results'])) {
                throw new RuntimeException("Radancy retornou uma resposta inesperada para {$this->companyName} (página {$page}).");
            }

            $html = $payload['results'];
            $totalPages = $this->extractTotalPages($html);
            if ($page === 1 && $totalPages !== null) {
                $lastPage = max(1, min($this->maxPages, $totalPages));
            }

            $cards = $this->extractCards($html, $origin);
            if ($cards === []) {
                break;
            }

            $fresh = 0;
            foreach ($cards as $card) {
                if (isset($seen[$card['id']])) {
                    continue;
                }
                $seen[$card['id']] = true;
                $fresh++;

                try {
                    $vacancy = $this->fetchDetails($card);
                    if ($vacancy !== null) {
                        $jobs[] = $vacancy;
                    }
                } catch (\Throwable $exception) {
                    Log::warning('Falha ao consultar detalhes de vaga Radancy.', [
                        'company' => $this->companyName,
                        'job_id' => $card['id'],
                        'url' => $card['url'],
                        'error' => $exception->getMessage(),
                    ]);
                }

                if (count($jobs) >= $this->maxJobs) {
                    return;
                }
            }

            if ($fresh === 0) {
                break;
            }

            $page++;
        } while ($page <= $lastPage);
    }

    private function fetchDetails(array $card): ?array
    {
        $response = Http::timeout(45)
            ->withOptions(['verify' => $this->caBundle ?: true])
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; TI-Vagas/1.0)',
                'Accept' => 'text/html,application/xhtml+xml',
            ])
            ->get($card['url']);

        $response->throw();
        $html = $response->body();
        $posting = $this->extractJobPosting($html);
        $title = $this->cleanText($posting['title'] ?? $card['title']);
        $description = $this->cleanDescription($this->scalar($posting['description'] ?? ''));
        $publishedAt = $this->parseDate($posting['datePosted'] ?? null)
            ?? $this->extractDateFromHtml($html);

        if ($title === '' || $description === '' || $publishedAt === null) {
            Log::warning('Vaga Radancy ignorada por falta de título, descrição ou data original verificável.', [
                'company' => $this->companyName,
                'job_id' => $card['id'],
                'has_title' => $title !== '',
                'has_description' => $description !== '',
                'has_published_at' => $publishedAt !== null,
            ]);
            return null;
        }

        $location = $this->extractPostingLocation($posting) ?: $card['location'];
        $workModel = $this->locationFilter->classifyWorkModel($title, $location, $description);

        if (!$this->locationFilter->isEligibleForCollection($location, $title, $description, $workModel)) {
            return null;
        }

        $category = $this->extractCategory($html, $posting);
        $technology = $this->isTechnologyRole($title, $description, $category);

        return [
            'title' => $title,
            'company' => $this->companyName,
            'description' => $description,
            'location' => $location !== '' ? $location : null,
            'work_model' => $workModel,
            'employment_type' => $this->normalizeEmploymentType($posting['employmentType'] ?? null),
            'salary' => null,
            'application_url' => $this->absoluteUrl($this->scalar($posting['url'] ?? ''), $card['url']) ?? $card['url'],
            'external_id' => $this->name() . ':' . $card['id'],
            'original_published_at' => $publishedAt,
            'source_category' => $category !== '' ? $category : null,
            'source_is_technology' => $technology,
        ];
    }

    private function resolveListUrl(): string
    {
        $parts = parse_url($this->url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['https', 'http'], true) || empty($parts['host'])) {
            throw new RuntimeException('URL Radancy inválida.');
        }

        $path = rtrim($parts['path'] ?? '', '/');
        if (preg_match('~/search-jobs$~', $path)) {
            return $parts['scheme'] . '://' . $parts['host'] . $path;
        }

        $locale = preg_match('~^/([a-z]{2})(?:/|$)~', $path, $matches) ? $matches[1] : 'en';
        return $parts['scheme'] . '://' . $parts['host'] . '/' . $locale . '/search-jobs';
    }

    private function extractCards(string $html, string $origin): array
    {
        $xpath = $this->xpath($html);
        // TalentBrew/Radancy's modern markup uses a stable BEM list-item
        // class; legacy tenants put the data-job-id directly on the anchor.
        $nodes = $xpath->query('//a[contains(@href, "/job/") and @data-job-id]');
        $cards = [];
        $seen = [];

        if ($nodes === false) {
            return [];
        }

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $href = trim($node->getAttribute('href'));
            $id = trim($node->getAttribute('data-job-id'));
            if ($id === '') {
                continue;
            }

            $titleNode = $xpath->query('.//h1|.//h2|.//h3|.//h4|.//*[contains(@class, "job-title")]|.//*[contains(@class, "jobtitle")]', $node)?->item(0);
            $title = $this->cleanText($titleNode?->textContent ?? $node->textContent);
            if ($href === '' || $title === '' || isset($seen[$id])) {
                continue;
            }

            $url = $this->absoluteUrl($href, $origin);
            if ($url === null || parse_url($url, PHP_URL_HOST) !== parse_url($origin, PHP_URL_HOST)) {
                continue;
            }

            $locationNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " job-location ")]|.//*[contains(concat(" ", normalize-space(@class), " "), " location ")]', $node)?->item(0);
            $location = $this->cleanText($locationNode?->textContent ?? '');

            $seen[$id] = true;
            $cards[] = ['id' => $id, 'title' => $title, 'url' => $url, 'location' => $location];
        }

        return $cards;
    }

    private function extractJobPosting(string $html): array
    {
        $xpath = $this->xpath($html);
        $scripts = $xpath->query('//script[contains(translate(@type, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "ld+json")]');
        if ($scripts === false) {
            return [];
        }

        foreach ($scripts as $script) {
            $data = json_decode(html_entity_decode($script->textContent ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
            $posting = $this->findJobPosting($data);
            if ($posting !== []) {
                return $posting;
            }
        }

        return [];
    }

    private function findJobPosting(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }

        $type = $data['@type'] ?? null;
        if ($type === 'JobPosting' || (is_array($type) && in_array('JobPosting', $type, true))) {
            return $data;
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $found = $this->findJobPosting($value);
                if ($found !== []) {
                    return $found;
                }
            }
        }

        return [];
    }

    private function extractPostingLocation(array $posting): string
    {
        $locations = $posting['jobLocation'] ?? [];
        if (isset($locations['address'])) {
            $locations = [$locations];
        }

        if (!is_array($locations)) {
            return '';
        }

        $parts = [];
        foreach ($locations as $place) {
            $address = is_array($place) ? ($place['address'] ?? []) : [];
            if (!is_array($address)) {
                continue;
            }
            $parts = array_filter([
                $this->scalar($address['addressLocality'] ?? ''),
                $this->scalar($address['addressRegion'] ?? ''),
                $this->scalar($address['addressCountry'] ?? ''),
            ]);
            if ($parts !== []) {
                break;
            }
        }

        return implode(', ', $parts);
    }

    private function extractCategory(string $html, array $posting): string
    {
        $values = [
            $this->scalar($posting['industry'] ?? ''),
            $this->scalar($posting['occupationalCategory'] ?? ''),
        ];
        if (preg_match('/(?:professional field|job category|category)[^<]{0,80}<[^>]*>\s*([^<]+)/i', $html, $matches)) {
            $values[] = $matches[1];
        }

        return $this->cleanText(implode(' ', array_filter($values)));
    }

    private function isTechnologyRole(string $title, string $description, string $category): bool
    {
        $text = Str::ascii(mb_strtolower($title . ' ' . $description . ' ' . $category));
        return (bool) preg_match('/\b(software|developer|development|engineer|engineering|devops|cloud|infrastructure|cyber|security|data|technology|technolog|tech|api|backend|back-end|frontend|front-end|full.?stack|qa|quality assurance|sistemas|desenvolvedor|desenvolvimento|programador|programacao|infraestrutura|suporte tecnico|analista de sistemas|redes|banco de dados|machine learning|artificial intelligence)\b/u', $text);
    }

    private function extractDateFromHtml(string $html): ?string
    {
        $xpath = $this->xpath($html);
        foreach ([
            '//meta[@property="article:published_time"]/@content',
            '//meta[@name="datePublished"]/@content',
            '//meta[@name="date"]/@content',
            '//meta[@itemprop="datePosted"]/@content',
            '//time[@datetime]/@datetime',
        ] as $query) {
            $nodes = $xpath->query($query);
            if ($nodes !== false) {
                foreach ($nodes as $node) {
                    $date = $this->parseDate($node->nodeValue);
                    if ($date !== null) {
                        return $date;
                    }
                }
            }
        }

        return null;
    }

    private function extractTotalPages(string $html): ?int
    {
        if (preg_match('/data-total-pages=["\'](\d+)["\']/i', $html, $matches)) {
            return max(1, (int) $matches[1]);
        }
        return null;
    }

    private function normalizeEmploymentType(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = implode(', ', array_filter($value, 'is_string'));
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return match (Str::upper(trim($value))) {
            'FULL_TIME' => 'Tempo integral',
            'PART_TIME' => 'Meio período',
            'CONTRACTOR' => 'Contrato',
            'TEMPORARY' => 'Temporário',
            'INTERN' => 'Estágio',
            default => trim($value),
        };
    }

    private function parseDate(mixed $value): ?string
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

    private function scalar(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value['name'] ?? $value['value'] ?? '';
        }
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function cleanDescription(string $value): string
    {
        $value = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', ' ', $value) ?? $value;
        $value = preg_replace('~</(p|div|li|br|h[1-6])\s*>~i', "\n", $value) ?? $value;
        return $this->cleanText(strip_tags($value));
    }

    private function cleanText(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new RuntimeException('Não foi possível interpretar HTML Radancy.');
        }
        return new DOMXPath($document);
    }

    private function absoluteUrl(string $url, string $base): ?string
    {
        if (trim($url) === '') {
            return null;
        }
        if (preg_match('~^https?://~i', $url)) {
            return $url;
        }
        $parts = parse_url($base);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        if (str_starts_with($url, '//')) {
            return $parts['scheme'] . ':' . $url;
        }
        return $parts['scheme'] . '://' . $parts['host'] . '/' . ltrim($url, '/');
    }
}
