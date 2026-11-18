<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Services\VacancyLocationFilter;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class FactorialSource implements VacancySource
{
    private readonly VacancyLocationFilter $locationFilter;

    public function __construct(
        private readonly string $companyKey,
        private readonly string $companyName,
        private readonly string $careerPageUrl,
        private readonly ?string $caBundle = null,
        private readonly int $maxJobs = 100,
        ?VacancyLocationFilter $locationFilter = null,
    ) {
        $this->locationFilter = $locationFilter ?? new VacancyLocationFilter();
    }

    public function name(): string
    {
        return 'factorial:' . $this->companyKey;
    }

    public function fetch(): iterable
    {
        $baseUrl = rtrim($this->careerPageUrl, '/');
        $indexHtml = $this->get($baseUrl . '/');
        $sitemap = $this->get($baseUrl . '/sitemap.xml');
        $publishedDates = $this->parseSitemap($sitemap);
        $indexJobs = $this->parseIndex($indexHtml, $baseUrl, $publishedDates);

        $vacancies = [];

        foreach (array_slice($indexJobs, 0, max(1, $this->maxJobs)) as $job) {
            $publishedAt = $job['published_at'];

            // The public Factorial portal does not expose the original
            // publication timestamp in the index/detail HTML. Sitemap
            // lastmod is the only public timestamp and is used conservatively.
            if ($publishedAt === null) {
                continue;
            }

            try {
                $publishedDate = CarbonImmutable::parse($publishedAt, 'UTC');
            } catch (\Throwable) {
                continue;
            }

            if ($publishedDate->lt(CarbonImmutable::now('UTC')->subDays(60)->startOfDay())) {
                continue;
            }

            $detailHtml = $this->get($job['url']);
            $detail = $this->parseDetail($detailHtml, $baseUrl);

            $description = $detail['description'];
            if ($description === '') {
                continue;
            }

            $location = $detail['location'] ?: $job['location'];
            $workModel = $job['remote'] ? 'Remoto' : null;

            if (!$this->locationFilter->isEligibleForCollection(
                $location,
                $job['title'],
                $description,
                $workModel
            )) {
                continue;
            }

            $vacancies[] = [
                'title' => $job['title'],
                'company' => $this->companyName,
                'description' => $description,
                'location' => $location !== '' ? $location : null,
                'work_model' => $workModel,
                'employment_type' => $this->normalizeEmployment($job['contract_type']),
                'salary' => null,
                'application_url' => $detail['application_url'] ?: $job['url'],
                'external_id' => $this->name() . ':' . $job['id'],
                'original_published_at' => $publishedDate->toIso8601String(),
            ];
        }

        return $vacancies;
    }

    private function get(string $url): string
    {
        $response = Http::timeout(45)
            ->withOptions(['verify' => $this->caBundle ?: true])
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; TI-Vagas/1.0)',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ])
            ->get($url);

        $response->throw();
        $body = $response->body();

        if (trim($body) === '') {
            throw new RuntimeException("A Factorial retornou conteúdo vazio para {$this->companyName}.");
        }

        return $body;
    }

    private function parseIndex(string $html, string $baseUrl, array $publishedDates): array
    {
        $document = $this->document($html);
        $xpath = new DOMXPath($document);
        $locationMap = $this->parseLocationOptions($xpath);
        $nodes = $xpath->query('//*[@data-controller="job-postings"]');
        $jobs = [];

        if ($nodes === false) {
            return [];
        }

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $url = trim($node->getAttribute('data-job-postings-url'));
            if ($url === '') {
                continue;
            }

            $url = $this->absoluteUrl($url, $baseUrl);
            if (!str_contains($url, '/job_posting/')) {
                continue;
            }

            if (!preg_match('~/job_posting/[^/?#]*-(\d+)(?:[?#].*)?$~', $url, $idMatch)) {
                continue;
            }

            $titleNode = $xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " font-bold ")]', $node)?->item(0);
            $title = $this->cleanText($titleNode?->textContent ?? '');
            if ($title === '') {
                continue;
            }

            $locationId = trim($node->getAttribute('data-location-id'));
            $location = $locationMap[$locationId] ?? '';
            $date = $publishedDates[$this->urlPath($url)] ?? null;

            $jobs[] = [
                'id' => $idMatch[1],
                'url' => $url,
                'title' => $title,
                'location' => $location,
                'remote' => strtolower(trim($node->getAttribute('data-is-remote'))) === 'true',
                'contract_type' => trim($node->getAttribute('data-contract-type')),
                'published_at' => $date,
            ];
        }

        $unique = [];
        foreach ($jobs as $job) {
            $unique[$job['id']] = $job;
        }

        return array_values($unique);
    }

    private function parseDetail(string $html, string $baseUrl): array
    {
        $document = $this->document($html);
        $xpath = new DOMXPath($document);

        $descriptionNode = $xpath->query(
            '//*[contains(concat(" ", normalize-space(@class), " "), " styledText ")]'
        )?->item(0);

        $description = '';
        if ($descriptionNode !== null) {
            $innerHtml = '';
            foreach ($descriptionNode->childNodes as $child) {
                $innerHtml .= $document->saveHTML($child);
            }
            $description = $this->cleanDescription($innerHtml);
        }

        $applyUrl = '';
        $links = $xpath->query('//a[@href]');
        if ($links !== false) {
            foreach ($links as $link) {
                if (!$link instanceof DOMElement) {
                    continue;
                }

                $href = trim($link->getAttribute('href'));
                if (str_starts_with($href, '/apply/')) {
                    $applyUrl = $this->absoluteUrl($href, $baseUrl);
                    break;
                }
            }
        }

        $location = '';
        $text = $this->cleanText($document->textContent);
        if (preg_match('/(?:Localização|Location)\s*[:\-]?\s*([^\n]{3,100})/iu', $text, $match)) {
            $location = trim($match[1]);
        }

        return [
            'description' => $description,
            'application_url' => $applyUrl,
            'location' => $location,
        ];
    }

    private function parseSitemap(string $xml): array
    {
        $document = $this->document($xml);
        $xpath = new DOMXPath($document);
        $entries = $xpath->query('//*[local-name()="url"]');
        $dates = [];

        if ($entries === false) {
            return [];
        }

        foreach ($entries as $entry) {
            $loc = trim($xpath->evaluate('string(./*[local-name()="loc"])', $entry));
            $lastmod = trim($xpath->evaluate('string(./*[local-name()="lastmod"])', $entry));

            if ($loc === '' || $lastmod === '' || !str_contains($loc, '/job_posting/')) {
                continue;
            }

            try {
                $date = CarbonImmutable::parse($lastmod, 'UTC')->startOfDay()->toIso8601String();
            } catch (\Throwable) {
                continue;
            }

            $dates[$this->urlPath($loc)] = $date;
        }

        return $dates;
    }

    private function parseLocationOptions(DOMXPath $xpath): array
    {
        $map = [];
        $options = $xpath->query('//select[@id="location_filter"]/option[@value]');
        if ($options === false) {
            return $map;
        }

        foreach ($options as $option) {
            if (!$option instanceof DOMElement) {
                continue;
            }

            $id = trim($option->getAttribute('value'));
            $name = $this->cleanText($option->textContent);
            if ($id !== '' && $name !== '') {
                $map[$id] = $name;
            }
        }

        return $map;
    }

    private function document(string $html): DOMDocument
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
            throw new RuntimeException('Não foi possível interpretar o HTML da Factorial.');
        }

        return $document;
    }

    private function absoluteUrl(string $url, string $baseUrl): string
    {
        if (preg_match('~^https?://~i', $url)) {
            return $url;
        }

        return $baseUrl . '/' . ltrim($url, '/');
    }

    private function urlPath(string $url): string
    {
        return rtrim((string) parse_url($url, PHP_URL_PATH), '/');
    }

    private function normalizeEmployment(string $type): ?string
    {
        $type = Str::lower(trim($type));

        return match ($type) {
            'indefinite', 'full_time' => 'Tempo integral',
            'temporary' => 'Temporário',
            'part-time', 'part_time' => 'Meio período',
            'intern', 'internship' => 'Estágio',
            'freelance', 'vendor_contractor' => 'Contrato',
            default => $type !== '' ? Str::headline($type) : null,
        };
    }

    private function cleanDescription(string $html): string
    {
        $html = preg_replace(
            '/<\s*br\s*\/?>|<\/\s*p\s*>|<\/\s*div\s*>|<\/\s*li\s*>|<\/\s*h[1-6]\s*>/i',
            "\n",
            $html
        );
        $html = preg_replace('/<\s*li\b[^>]*>/i', "\n- ", $html);
        $text = html_entity_decode(
            strip_tags($html),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        return trim(preg_replace('/\n{3,}/', "\n\n", preg_replace('/[ \t]+/', ' ', $text)));
    }

    private function cleanText(string $value): string
    {
        $value = html_entity_decode(
            strip_tags($value),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        return trim(preg_replace('/\s+/u', ' ', $value));
    }
}
