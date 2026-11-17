<?php

namespace App\\Sources;

use App\\Contracts\\VacancySource;
use App\\Services\\VacancyLocationFilter;
use Carbon\\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\\Support\\Facades\\Http;
use Illuminate\\Support\\Str;
use RuntimeException;
use Throwable;

class MindsightSource implements VacancySource
{
    private readonly VacancyLocationFilter $locationFilter;

    public function __construct(
        private readonly string $tenant,
        private readonly string $companyName,
        private readonly string $baseUrl,
        private readonly ?string $caBundle = null,
        private readonly int $maxJobs = 200,
        ?VacancyLocationFilter $locationFilter = null,
    ) {
        $this->locationFilter = $locationFilter ?? new VacancyLocationFilter();
    }

    public function name(): string
    {
        return 'mindsight-' . $this->tenant;
    }

    public function fetch(): iterable
    {
        $response = $this->request(rtrim($this->baseUrl, '/') . '/');
        $html = $response->body();
        $ids = $this->extractJobIds($html);
        $vacancies = [];

        foreach (array_slice($ids, 0, $this->maxJobs) as $id) {
            $detailUrl = rtrim($this->baseUrl, '/') . '/' . rawurlencode($id) . '/';

            try {
                $detail = $this->request($detailUrl)->body();
                $vacancy = $this->normalize($id, $detail, $detailUrl);

                if ($vacancy !== null) {
                    $vacancies[] = $vacancy;
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $vacancies;
    }

    private function request(string $url)
    {
        $response = Http::accept('text/html,application/xhtml+xml')
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; TI-Vagas/1.0)'])
            ->timeout(30)
            ->withOptions(['verify' => $this->caBundle ?: true])
            ->get($url);

        $response->throw();

        return $response;
    }

    private function extractJobIds(string $html): array
    {
        $dom = $this->dom($html);
        $xpath = new DOMXPath($dom);
        $ids = [];

        foreach ($xpath->query('//a[@href]') ?: [] as $anchor) {
            $href = trim($anchor->getAttribute('href'));

            if (preg_match('~/' . preg_quote($this->tenant, '~') . '/(\\d+)/?(?:[?#].*)?$~', $href, $m)
                || preg_match('~^/(\\d+)/?(?:[?#].*)?$~', $href, $m)) {
                $ids[] = $m[1];
            }
        }

        if ($ids === []) {
            preg_match_all('~href=["\\'](?:https?://[^/"\\']+)?/' . preg_quote($this->tenant, '~') . '/(\\d+)/?["\\']~i', $html, $matches);
            $ids = $matches[1] ?? [];
        }

        return array_values(array_unique($ids));
    }

    private function normalize(string $id, string $html, string $url): ?array
    {
        $dom = $this->dom($html);
        $xpath = new DOMXPath($dom);
        $structured = $this->findJobPosting($this->extractStructuredData($dom));
        $title = trim((string) ($structured['title'] ?? $structured['name'] ?? ''));

        if ($title === '') {
            $title = $this->firstText($xpath, [
                '//main//h1', '//article//h1', '//h1',
                '//meta[@property="og:title"]/@content',
            ]);
        }

        if ($title === '') {
            return null;
        }

        $description = $this->cleanDescription((string) (
            $structured['description'] ?? $this->firstText($xpath, [
                '//main', '//article', '//*[@role="main"]',
            ])
        ));

        $location = $this->structuredLocation($structured);
        if ($location === '') {
            $location = $this->firstText($xpath, [
                '//*[contains(translate(@class,"LOCAL","local"),"local")]',
                '//*[contains(translate(@class,"CITY","city"),"city")]',
            ]);
        }

        $workModel = $this->workModel(
            (string) ($structured['jobLocationType'] ?? ''),
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

        $date = $structured['datePosted'] ?? null;
        $publishedAt = null;

        if (is_string($date) && trim($date) !== '') {
            try {
                $publishedAt = CarbonImmutable::parse($date)->utc()->toIso8601String();
            } catch (Throwable) {
                $publishedAt = null;
            }
        }

        return [
            'title' => $title,
            'company' => $this->companyName,
            'description' => $description,
            'location' => $location !== '' ? $location : null,
            'work_model' => $workModel,
            'employment_type' => $this->employmentType($structured['employmentType'] ?? null),
            'salary' => null,
            'application_url' => $url,
            'external_id' => $id,
            'original_published_at' => $publishedAt,
        ];
    }

    private function dom(string $html): DOMDocument
    {
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);

        return $dom;
    }

    private function extractStructuredData(DOMDocument $dom): array
    {
        $xpath = new DOMXPath($dom);
        $result = [];

        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $script) {
            $decoded = json_decode($script->textContent, true);
            if (is_array($decoded)) {
                $result[] = $decoded;
            }
        }

        $next = $dom->getElementById('__NEXT_DATA__');
        if ($next !== null) {
            $decoded = json_decode($next->textContent, true);
            if (is_array($decoded)) {
                $result[] = $decoded;
            }
        }

        return $result;
    }

    private function findJobPosting(array $nodes): ?array
    {
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $type = $node['@type'] ?? null;
            if ($type === 'JobPosting' || (is_array($type) && in_array('JobPosting', $type, true))) {
                return $node;
            }

            $found = $this->findJobPosting($node);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function structuredLocation(array $job): string
    {
        $locations = $job['jobLocation'] ?? [];
        if (!is_array($locations)) {
            return '';
        }

        if (!array_is_list($locations)) {
            $locations = [$locations];
        }

        $parts = [];
        foreach ($locations as $item) {
            $address = $item['address'] ?? [];
            if (!is_array($address)) {
                continue;
            }

            $parts[] = implode(', ', array_filter([
                $address['addressLocality'] ?? null,
                $address['addressRegion'] ?? null,
                is_array($address['addressCountry'] ?? null)
                    ? ($address['addressCountry']['name'] ?? null)
                    : ($address['addressCountry'] ?? null),
            ]));
        }

        return implode(' / ', array_filter($parts));
    }

    private function firstText(DOMXPath $xpath, array $queries): string
    {
        foreach ($queries as $query) {
            $nodes = $xpath->query($query);
            if (!$nodes || $nodes->length === 0) {
                continue;
            }

            $node = $nodes->item(0);
            $value = $node instanceof DOMAttr
                ? $node->value
                : $node->textContent;

            $value = trim(preg_replace('/\\s+/u', ' ', $value) ?? '');
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function cleanDescription(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('~</(?:p|div|li|h[1-6])>~i', "\\n", $value);
        $value = strip_tags($value);
        $value = preg_replace('/[ \\t]+/', ' ', $value);
        $value = preg_replace('/ *\\n */', "\\n", $value);

        return trim(preg_replace('/\\n{3,}/', "\\n\\n", $value) ?? '');
    }

    private function workModel(string $type, string $description): ?string
    {
        $value = Str::ascii(mb_strtolower($type . ' ' . $description));

        return match (true) {
            str_contains($value, 'telecommute'),
            str_contains($value, 'remoto') => 'Remoto',
            str_contains($value, 'hibrid') => 'Híbrido',
            str_contains($value, 'on-site'),
            str_contains($value, 'presencial') => 'Presencial',
            default => null,
        };
    }

    private function employmentType(mixed $type): ?string
    {
        if (is_array($type)) {
            $type = $type[0] ?? null;
        }

        $value = Str::ascii(mb_strtolower((string) $type));

        return match (true) {
            str_contains($value, 'full_time'),
            str_contains($value, 'clt') => 'CLT',
            str_contains($value, 'contract'),
            str_contains($value, 'pj') => 'PJ',
            str_contains($value, 'intern') => 'Estágio',
            str_contains($value, 'temporary') => 'Temporário',
            default => null,
        };
    }
}
