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

class OwnCareerPageSource implements VacancySource
{
    private readonly VacancyLocationFilter $locationFilter;

    public function __construct(
        private readonly string $companyKey,
        private readonly string $companyName,
        private readonly string $url,
        private readonly ?string $caBundle = null,
        ?VacancyLocationFilter $locationFilter = null,
    ) {
        $this->locationFilter = $locationFilter ?? new VacancyLocationFilter();
    }

    public function name(): string
    {
        return 'career-page:' . $this->companyKey;
    }

    public function fetch(): iterable
    {
        $html = $this->getPage($this->url);
        $pages = [$this->url => $html];

        // Se a oportunidade tiver uma página própria, consultá-la também.
        foreach ($this->extractDetailUrls($html) as $detailUrl) {
            if ($detailUrl === $this->url || isset($pages[$detailUrl])) {
                continue;
            }

            try {
                $pages[$detailUrl] = $this->getPage($detailUrl);
            } catch (\Throwable $exception) {
                Log::warning('Falha ao abrir página de detalhes de carreira.', [
                    'company' => $this->companyName,
                    'url' => $detailUrl,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $vacancies = [];
        $seen = [];

        foreach ($pages as $pageUrl => $pageHtml) {
            foreach ($this->extractJobPostings($pageHtml) as $posting) {
                $vacancy = $this->normalizePosting($posting, $pageUrl);

                if ($vacancy !== null) {
                    $key = $vacancy['external_id'];
                    if (!isset($seen[$key])) {
                        $seen[$key] = true;
                        $vacancies[] = $vacancy;
                    }
                }
            }
        }

        // Talklink publica a oportunidade diretamente na página /vagas,
        // sem necessariamente fornecer uma URL individual ou JSON-LD.
        if ($this->companyKey === 'talklink') {
            $talklinkVacancy = $this->extractTalklinkVacancy(
                $html,
                $this->url
            );

            if ($talklinkVacancy !== null) {
                $key = $talklinkVacancy['external_id'];
                if (!isset($seen[$key])) {
                    $vacancies[] = $talklinkVacancy;
                }
            }
        }

        return $vacancies;
    }

    private function getPage(string $url): string
    {
        $response = Http::timeout(45)
            ->withOptions(['verify' => $this->caBundle ?: true])
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; TI-Vagas/1.0)',
                'Accept' => 'text/html,application/xhtml+xml,application/ld+json',
            ])
            ->get($url);

        $response->throw();

        $html = $response->body();

        if (trim($html) === '') {
            throw new RuntimeException(
                "A página de carreira de {$this->companyName} retornou conteúdo vazio."
            );
        }

        return $html;
    }

    private function extractDetailUrls(string $html): array
    {
        $xpath = $this->xpath($html);
        $nodes = $xpath->query('//a[@href]');

        if ($nodes === false) {
            return [];
        }

        $urls = [];

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $label = $this->cleanText($node->textContent);
            $href = trim($node->getAttribute('href'));

            if (
                $href === ''
                || !preg_match('/vaga|oportunidade|career|job|position/i', $label . ' ' . $href)
            ) {
                continue;
            }

            $absolute = $this->absoluteUrl($href, $this->url);

            if ($absolute !== null && parse_url($absolute, PHP_URL_HOST) === parse_url($this->url, PHP_URL_HOST)) {
                $urls[] = $absolute;
            }
        }

        return array_values(array_unique($urls));
    }

    private function extractJobPostings(string $html): array
    {
        $xpath = $this->xpath($html);
        $scripts = $xpath->query(
            '//script[contains(translate(@type, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "ld+json")]'
        );

        if ($scripts === false) {
            return [];
        }

        $postings = [];

        foreach ($scripts as $script) {
            $decoded = json_decode(
                html_entity_decode($script->textContent ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                true
            );

            if (is_array($decoded)) {
                $this->findJobPostings($decoded, $postings);
            }
        }

        return $postings;
    }

    private function findJobPostings(array $data, array &$postings): void
    {
        $type = $data['@type'] ?? null;

        if (
            $type === 'JobPosting'
            || (is_array($type) && in_array('JobPosting', $type, true))
        ) {
            $postings[] = $data;
            return;
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                if (array_is_list($value)) {
                    foreach ($value as $item) {
                        if (is_array($item)) {
                            $this->findJobPostings($item, $postings);
                        }
                    }
                } else {
                    $this->findJobPostings($value, $postings);
                }
            }
        }
    }

    private function normalizePosting(array $posting, string $pageUrl): ?array
    {
        $title = $this->cleanText($this->scalar($posting['title'] ?? null));
        $description = $this->cleanDescription($this->scalar($posting['description'] ?? null));
        $publishedAt = $this->extractPublicationDate($pageUrl, $posting);

        if ($title === '' || $description === '') {
            return null;
        }

        if ($publishedAt === null) {
            Log::warning('Vaga encontrada sem data original de publicação; não será importada.', [
                'company' => $this->companyName,
                'title' => $title,
                'url' => $pageUrl,
            ]);
            return null;
        }

        $location = $this->extractLocation($posting);
        $workModel = $this->locationFilter->classifyWorkModel(
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

        $id = $this->scalar($posting['identifier']['value'] ?? null)
            ?: $this->scalar($posting['identifier'] ?? null)
            ?: $this->scalar($posting['url'] ?? null)
            ?: $pageUrl;

        return [
            'title' => $title,
            'company' => $this->companyName,
            'description' => $description,
            'location' => $location !== '' ? $location : null,
            'work_model' => $workModel,
            'employment_type' => $this->normalizeEmploymentType($posting['employmentType'] ?? null),
            'salary' => $this->extractSalary($posting['baseSalary'] ?? null),
            'application_url' => $this->absoluteUrl(
                $this->scalar($posting['url'] ?? null),
                $pageUrl
            ) ?? $pageUrl,
            'external_id' => $this->name() . ':' . Str::slug($id),
            'original_published_at' => $publishedAt,
        ];
    }

    private function extractTalklinkVacancy(string $html, string $pageUrl): ?array
    {
        $xpath = $this->xpath($html);
        $heading = $xpath->query(
            '//*[self::h1 or self::h2 or self::h3 or self::h4][contains(translate(normalize-space(.), "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "suporte técnico ao cliente")]'
        )?->item(0);

        if (!$heading instanceof DOMElement) {
            return null;
        }

        $title = $this->cleanText($heading->textContent);
        $container = $heading->parentNode;

        // Sobe alguns níveis para capturar atividades, requisitos e benefícios.
        for ($i = 0; $i < 4 && $container?->parentNode !== null; $i++) {
            if ($container instanceof DOMElement && mb_strlen($container->textContent) > 250) {
                break;
            }
            $container = $container->parentNode;
        }

        $description = $this->cleanText(
            $container?->textContent ?? $heading->textContent
        );
        $vacancyHtml = $container !== null
            ? ($container->ownerDocument?->saveHTML($container) ?: '')
            : '';

        if ($title === '' || mb_strlen($description) < 100) {
            return null;
        }

        // Verifica primeiro a própria página e seus metadados; se existir
        // uma URL individual descoberta, ela também já foi consultada acima.
        // Examina somente o bloco da oportunidade. Metadados globais da
        // página podem representar a publicação do site, não desta vaga.
        $publishedAt = $this->extractPublicationDate(
            $pageUrl,
            [],
            $vacancyHtml
        );

        if ($publishedAt === null) {
            Log::warning('Talklink: vaga Suporte Técnico ao Cliente encontrada, mas sem data original de publicação verificável.', [
                'url' => $pageUrl,
            ]);
            return null;
        }

        // O endereço da empresa no rodapé não comprova o local de trabalho
        // desta vaga; não inferir a localização a partir dele.
        $location = '';
        $workModel = $this->locationFilter->classifyWorkModel(
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

        return [
            'title' => $title,
            'company' => $this->companyName,
            'description' => $description,
            'location' => $location,
            'work_model' => $workModel,
            'employment_type' => null,
            'salary' => null,
            'application_url' => $pageUrl,
            'external_id' => $this->name() . ':suporte-tecnico-ao-cliente',
            'original_published_at' => $publishedAt,
        ];
    }

    private function extractPublicationDate(
        string $pageUrl,
        array $posting = [],
        ?string $html = null
    ): ?string {
        $candidates = [
            $posting['datePosted'] ?? null,
            $posting['datePublished'] ?? null,
        ];

        if ($html === null) {
            // O chamador pode fornecer o HTML para inspecionar os metadados.
            $html = '';
        }

        if ($html !== '') {
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
                        $candidates[] = $node->nodeValue;
                    }
                }
            }

            $visibleText = $this->cleanText(
                $xpath->query('//body')?->item(0)?->textContent ?? ''
            );

            if (preg_match_all(
                '/(?:publicad[oa]|data\s+de\s+publica[çc][ãa]o|publicação)\s*(?:em|:)?\s*(\d{1,2}[\/.-]\d{1,2}[\/.-]\d{2,4})/iu',
                $visibleText,
                $matches
            )) {
                foreach ($matches[1] as $date) {
                    $candidates[] = $date;
                }
            }
        }

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            try {
                if (preg_match('/^\d{1,2}[\/.-]\d{1,2}[\/.-]\d{2,4}$/', trim($candidate))) {
                    $date = str_replace(['.', '-'], '/', trim($candidate));
                    foreach (['!d/m/Y', '!d/m/y'] as $format) {
                        $parsed = CarbonImmutable::createFromFormat($format, $date, 'UTC');
                        if ($parsed !== false) {
                            return $parsed->toIso8601String();
                        }
                    }
                    continue;
                }

                return CarbonImmutable::parse($candidate, 'UTC')->toIso8601String();
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function extractLocation(array $posting): string
    {
        $locations = $posting['jobLocation'] ?? [];
        if (isset($locations['address'])) {
            $locations = [$locations];
        }

        if (!is_array($locations)) {
            return '';
        }

        foreach ($locations as $place) {
            if (!is_array($place)) {
                continue;
            }
            $address = $place['address'] ?? [];
            if (!is_array($address)) {
                continue;
            }

            $parts = array_filter([
                $this->scalar($address['addressLocality'] ?? null),
                $this->scalar($address['addressRegion'] ?? null),
                $this->scalar($address['addressCountry'] ?? null),
            ]);

            if ($parts !== []) {
                return implode(', ', $parts);
            }
        }

        return '';
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

    private function extractSalary(mixed $salary): ?string
    {
        if (!is_array($salary)) {
            return null;
        }

        $value = $salary['value'] ?? null;
        if (is_array($value)) {
            $min = $value['minValue'] ?? null;
            $max = $value['maxValue'] ?? null;
            if (is_numeric($min) && is_numeric($max)) {
                return $min . ' - ' . $max;
            }
            if (is_numeric($min)) {
                return (string) $min;
            }
            if (is_numeric($max)) {
                return (string) $max;
            }
        }

        return null;
    }

    private function scalar(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value['name'] ?? $value['value'] ?? $value['@value'] ?? '';
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function cleanDescription(string $value): string
    {
        $value = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', ' ', $value);
        $value = preg_replace('~</(p|div|li|br|h[1-6])\s*>~i', "\n", $value);
        return $this->cleanText(strip_tags($value));
    }

    private function cleanText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            throw new RuntimeException('Não foi possível interpretar o HTML da página de carreira.');
        }

        return new DOMXPath($document);
    }

    private function absoluteUrl(?string $url, string $baseUrl): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        if (str_starts_with($url, 'https://') || str_starts_with($url, 'http://')) {
            return $url;
        }

        $parts = parse_url($baseUrl);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        if (str_starts_with($url, '//')) {
            return $parts['scheme'] . ':' . $url;
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (str_starts_with($url, '/')) {
            return $origin . $url;
        }

        $path = $parts['path'] ?? '/';
        return $origin . rtrim(dirname($path), '/') . '/' . $url;
    }
}
