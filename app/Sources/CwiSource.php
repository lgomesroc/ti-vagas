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

class CwiSource implements VacancySource
{
    private ?array $restBases = null;

    private array $publishedDates = [];

    public function __construct(
        private readonly ?string $caBundle = null,
        private readonly ?VacancyLocationFilter $locationFilter = null,
    ) {
    }

    public function name(): string
    {
        return 'cwi';
    }

    public function fetch(): iterable
    {
        $listingUrl = 'https://cwi.com.br/talentos/oportunidades/';
        $listingHtml = $this->getPage($listingUrl);
        $vacancies = [];
        $seen = [];

        foreach ($this->extractCards($listingHtml) as $card) {
            try {
                $detailHtml = $this->getPage($card['url']);
                $vacancy = $this->normalizeCard($card, $detailHtml);

                if ($vacancy === null) {
                    continue;
                }

                if (!isset($seen[$vacancy['external_id']])) {
                    $seen[$vacancy['external_id']] = true;
                    $vacancies[] = $vacancy;
                }
            } catch (\Throwable $exception) {
                Log::warning('Falha ao coletar oportunidade da CWI.', [
                    'url' => $card['url'],
                    'error' => $exception->getMessage(),
                ]);
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
                'Accept' => 'text/html,application/xhtml+xml,application/json',
            ])
            ->get($url);

        $response->throw();

        $html = $response->body();

        if (trim($html) === '') {
            throw new RuntimeException('A CWI retornou uma página vazia.');
        }

        return $html;
    }

    private function extractCards(string $html): array
    {
        $xpath = $this->xpath($html);
        $nodes = $xpath->query(
            '//a[contains(concat(" ", normalize-space(@class), " "), " opportunity-card__link ") and @href]'
        );

        if ($nodes === false) {
            return [];
        }

        $cards = [];

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $href = trim($node->getAttribute('href'));
            $titleNode = $xpath->query(
                './/*[contains(concat(" ", normalize-space(@class), " "), " opportunity-card__text ")]',
                $node
            )?->item(0);

            $title = $this->cleanText($titleNode?->textContent ?? '');

            if ($title === '' || $href === '') {
                continue;
            }

            $url = $this->absoluteUrl($href);

            if ($url === null || parse_url($url, PHP_URL_HOST) !== 'cwi.com.br') {
                continue;
            }

            $pillNodes = $xpath->query(
                './/*[contains(concat(" ", normalize-space(@class), " "), " pills__pill ")]',
                $node
            );

            $pills = [];

            if ($pillNodes !== false) {
                foreach ($pillNodes as $pill) {
                    $value = $this->cleanText($pill->textContent);
                    if ($value !== '') {
                        $pills[] = $value;
                    }
                }
            }

            $cards[] = [
                'title' => $title,
                'url' => $url,
                'work_model_label' => $pills[0] ?? '',
                'location_label' => $pills[1] ?? '',
            ];
        }

        return $cards;
    }

    private function normalizeCard(array $card, string $html): ?array
    {
        $xpath = $this->xpath($html);
        $heading = $xpath->query('//h1')?->item(0);
        $title = $this->cleanText($heading?->textContent ?? $card['title']);
        $title = preg_replace('/^oportunidade\s*:\s*/iu', '', $title) ?? $title;

        $descriptionNode = $xpath->query(
            '//*[contains(concat(" ", normalize-space(@class), " "), " opportunity-description__content ")]'
        )?->item(0);

        if (!$descriptionNode instanceof DOMElement) {
            return null;
        }

        $descriptionElement = $descriptionNode->cloneNode(true);

        if ($descriptionElement instanceof DOMElement) {
            $links = (new DOMXPath($descriptionElement->ownerDocument))
                ->query('.//a', $descriptionElement);

            if ($links !== false) {
                $toRemove = [];
                foreach ($links as $link) {
                    $toRemove[] = $link;
                }
                foreach ($toRemove as $link) {
                    $link->parentNode?->removeChild($link);
                }
            }
        }

        $descriptionParts = [];
        $intro = $xpath->query('//h1/following::p[1]')?->item(0);

        if ($intro !== null) {
            $introText = $this->cleanText($intro->textContent);
            if ($introText !== '') {
                $descriptionParts[] = $introText;
            }
        }

        $requirements = $this->cleanText(
            $descriptionElement?->textContent ?? ''
        );

        if ($requirements !== '') {
            $descriptionParts[] = $requirements;
        }

        $description = implode("\n\n", array_unique($descriptionParts));

        if ($title === '' || $description === '') {
            return null;
        }

        $applicationNode = $xpath->query(
            '//a[contains(concat(" ", normalize-space(@class), " "), " opportunity-description__link ") and @href]'
        )?->item(0);

        $applicationUrl = $applicationNode instanceof DOMElement
            ? $this->absoluteUrl($applicationNode->getAttribute('href'))
            : null;

        $location = $this->normalizeLocation($card['location_label']);
        $workModelLabel = $card['work_model_label'];
        $filter = $this->locationFilter ?? new VacancyLocationFilter();
        $workModel = $filter->classifyWorkModel(
            $workModelLabel,
            $location,
            $description
        );

        if (!$filter->isEligibleForCollection(
            $location,
            $title,
            $description,
            $workModel
        )) {
            return null;
        }

        $slug = trim((string) parse_url($card['url'], PHP_URL_PATH), '/');
        $slug = Str::afterLast($slug, '/');
        $publishedAt = $this->publicationDate($html, $slug);

        if ($publishedAt === null) {
            Log::warning('Oportunidade da CWI sem data original de publicação verificável; não será importada.', [
                'title' => $title,
                'url' => $card['url'],
            ]);

            return null;
        }

        return [
            'title' => $title,
            'company' => 'CWI Software',
            'description' => $description,
            'location' => $location !== '' ? $location : null,
            'work_model' => $workModel,
            'employment_type' => null,
            'salary' => null,
            'application_url' => $applicationUrl ?? $card['url'],
            'external_id' => $this->name() . ':' . $slug,
            'original_published_at' => $publishedAt,
        ];
    }

    private function publicationDate(string $html, string $slug): ?string
    {
        if (array_key_exists($slug, $this->publishedDates)) {
            return $this->publishedDates[$slug];
        }

        $candidates = [];
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

        $scripts = $xpath->query(
            '//script[contains(translate(@type, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "ld+json")]'
        );

        if ($scripts !== false) {
            foreach ($scripts as $script) {
                $data = json_decode($script->textContent ?? '', true);
                if (!is_array($data)) {
                    continue;
                }

                $this->collectJobPostingDates($data, $candidates);
            }
        }

        foreach ($candidates as $candidate) {
            $date = $this->parseDate($candidate);
            if ($date !== null) {
                return $this->publishedDates[$slug] = $date;
            }
        }

        $date = $this->dateFromWordPress($slug);

        if ($date !== null) {
            return $this->publishedDates[$slug] = $date;
        }

        $this->publishedDates[$slug] = null;

        return null;
    }

    private function collectJobPostingDates(array $data, array &$dates): void
    {
        $type = $data['@type'] ?? null;

        if (
            $type === 'JobPosting'
            || (is_array($type) && in_array('JobPosting', $type, true))
        ) {
            $dates[] = $data['datePosted'] ?? null;
            $dates[] = $data['datePublished'] ?? null;
            return;
        }

        foreach ($data as $value) {
            if (!is_array($value)) {
                continue;
            }

            if (array_is_list($value)) {
                foreach ($value as $item) {
                    if (is_array($item)) {
                        $this->collectJobPostingDates($item, $dates);
                    }
                }
            } else {
                $this->collectJobPostingDates($value, $dates);
            }
        }
    }

    private function dateFromWordPress(string $slug): ?string
    {
        $restBases = $this->wordPressRestBases();

        foreach ($restBases as $restBase) {
            try {
                $response = Http::timeout(20)
                    ->withOptions(['verify' => $this->caBundle ?: true])
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; TI-Vagas/1.0)'])
                    ->get('https://cwi.com.br/wp-json/wp/v2/' . rawurlencode($restBase), [
                        'slug' => $slug,
                        '_fields' => 'date,date_gmt,link,slug',
                    ]);

                if (!$response->successful()) {
                    continue;
                }

                $items = $response->json();

                if (!is_array($items) || !array_is_list($items)) {
                    continue;
                }

                foreach ($items as $item) {
                    if (!is_array($item) || ($item['slug'] ?? null) !== $slug) {
                        continue;
                    }

                    $date = $this->parseDate(
                        $item['date_gmt'] ?? null
                    ) ?? $this->parseDate($item['date'] ?? null);

                    if ($date !== null) {
                        return $date;
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function wordPressRestBases(): array
    {
        if ($this->restBases !== null) {
            return $this->restBases;
        }

        $this->restBases = [];

        try {
            $response = Http::timeout(20)
                ->withOptions(['verify' => $this->caBundle ?: true])
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; TI-Vagas/1.0)'])
                ->get('https://cwi.com.br/wp-json/wp/v2/types');

            if (!$response->successful()) {
                return $this->restBases;
            }

            $types = $response->json();

            if (!is_array($types)) {
                return $this->restBases;
            }

            foreach ($types as $type) {
                if (!is_array($type) || empty($type['rest_base'])) {
                    continue;
                }

                $restBase = (string) $type['rest_base'];

                if (in_array($restBase, ['posts', 'pages', 'media'], true)) {
                    continue;
                }

                if (($type['viewable'] ?? true) === false) {
                    continue;
                }

                $this->restBases[] = $restBase;
            }
        } catch (\Throwable) {
            // A página HTML continua sendo lida; a API serve apenas para
            // recuperar a data original quando o tema não a exibe.
        }

        return array_values(array_unique($this->restBases));
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

    private function normalizeLocation(string $value): string
    {
        $value = $this->cleanText($value);

        if ($value === '') {
            return '';
        }

        if (preg_match('/\b(?:AC|AL|AP|AM|BA|CE|DF|ES|GO|MA|MT|MS|MG|PA|PB|PR|PE|PI|RJ|RN|RS|RO|RR|SC|SP|SE|TO)\b/i', $value)) {
            return $value . ', Brasil';
        }

        return $value;
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
            throw new RuntimeException('Não foi possível interpretar o HTML da CWI.');
        }

        return new DOMXPath($document);
    }

    private function cleanText(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    private function absoluteUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, 'https://') || str_starts_with($url, 'http://')) {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        return 'https://cwi.com.br/' . ltrim($url, '/');
    }
}
