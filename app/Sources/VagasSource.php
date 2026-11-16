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

class VagasSource implements VacancySource
{
    private readonly VacancyLocationFilter $locationFilter;

    public function __construct(
        private readonly string $companyKey,
        private readonly string $companyName,
        private readonly string $url,
        private readonly ?string $caBundle = null,
        ?VacancyLocationFilter $locationFilter = null,
        private readonly int $maxJobs = 100,
    ) {
        $this->locationFilter = $locationFilter ?? new VacancyLocationFilter();
    }

    public function name(): string
    {
        return 'vagas:' . $this->companyKey;
    }

    public function fetch(): iterable
    {
        $listingHtml = $this->getPage($this->url);
        $xpath = $this->xpath($listingHtml);
        $nodes = $xpath->query('//a[contains(@href, "/vagas/v")]');

        if ($nodes === false) {
            return [];
        }

        $detailUrls = [];
        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $href = trim($node->getAttribute('href'));
            $url = $this->absoluteUrl($href);

            if ($url !== null && preg_match('~/vagas/v\d+/~', $url)) {
                $detailUrls[$url] = true;
            }
        }

        $vacancies = [];
        foreach (array_slice(array_keys($detailUrls), 0, $this->maxJobs) as $detailUrl) {
            try {
                $vacancy = $this->parseDetail($this->getPage($detailUrl), $detailUrl);
                if ($vacancy !== null) {
                    $vacancies[] = $vacancy;
                }
            } catch (\Throwable $exception) {
                Log::warning('Falha ao coletar vaga no Vagas.com.', [
                    'company' => $this->companyName,
                    'url' => $detailUrl,
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
                'Accept' => 'text/html,application/xhtml+xml',
            ])
            ->get($url);

        $response->throw();
        $html = $response->body();

        if (trim($html) === '') {
            throw new RuntimeException('O Vagas.com retornou uma página vazia.');
        }

        return $html;
    }

    private function parseDetail(string $html, string $url): ?array
    {
        $xpath = $this->xpath($html);
        $title = $this->text($xpath, '//h1');
        $company = $this->text($xpath, '//*[self::h2 or self::h3][1]');

        if ($title === '' || !$this->matchesCompany($html, $company)) {
            return null;
        }

        $description = $this->text($xpath, '//*[contains(normalize-space(.), "Descrição:")][1]');
        if ($description === '') {
            $description = $this->text($xpath, '//main');
        }

        $description = trim(preg_replace('/\s+/u', ' ', $description) ?? '');
        if ($description === '') {
            return null;
        }

        $publishedAt = $this->publicationDate($xpath);
        if ($publishedAt === null) {
            Log::warning('Vagas.com: vaga sem data original de publicação verificável; ignorada.', [
                'company' => $this->companyName,
                'title' => $title,
                'url' => $url,
            ]);
            return null;
        }

        $location = $this->text($xpath, '//*[contains(., "Localização")]/following::*[1]');
        if ($location === '') {
            $location = $this->text($xpath, '//*[contains(., "Rio de Janeiro") or contains(., "São Paulo") or contains(., "Belo Horizonte") or contains(., "Home Office")][1]');
        }

        $workModel = $this->locationFilter->classifyWorkModel($title, $location, $description);
        if (!$this->locationFilter->isEligibleForCollection($location, $title, $description, $workModel)) {
            return null;
        }

        $id = basename(parse_url($url, PHP_URL_PATH) ?: $url);
        return [
            'title' => $title,
            'company' => $this->companyName,
            'description' => $description,
            'location' => $location !== '' ? $location : null,
            'work_model' => $workModel,
            'employment_type' => $this->text($xpath, '//*[contains(., "Estágio") or contains(., "Júnior") or contains(., "Técnico")][1]') ?: null,
            'salary' => null,
            'application_url' => $url,
            'external_id' => $this->name() . ':' . Str::slug($id),
            'original_published_at' => $publishedAt,
        ];
    }

    private function matchesCompany(string $html, string $displayedCompany): bool
    {
        $normalized = Str::lower($displayedCompany . ' ' . strip_tags($html));
        return match (Str::lower($this->companyKey)) {
            'sgr' => str_contains($normalized, 'sgr'),
            'editora-globo' => str_contains($normalized, 'editora globo'),
            default => str_contains($normalized, Str::lower($this->companyName)),
        };
    }

    private function publicationDate(DOMXPath $xpath): ?string
    {
        foreach ([
            '//meta[@property="article:published_time"]/@content',
            '//meta[@name="datePublished"]/@content',
            '//meta[@itemprop="datePosted"]/@content',
            '//time[@datetime]/@datetime',
        ] as $query) {
            $nodes = $xpath->query($query);
            if ($nodes === false) {
                continue;
            }
            foreach ($nodes as $node) {
                $value = trim((string) $node->nodeValue);
                if ($value !== '') {
                    try {
                        return CarbonImmutable::parse($value, 'UTC')->toIso8601String();
                    } catch (\Throwable) {
                    }
                }
            }
        }

        $body = $this->text($xpath, '//body');
        if (preg_match('/(?:publicad[oa]|publicada em)\s*(\d{1,2}\/\d{1,2}\/\d{4})/iu', $body, $match)) {
            try {
                return CarbonImmutable::createFromFormat('!d/m/Y', $match[1], 'UTC')?->toIso8601String();
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function text(DOMXPath $xpath, string $query): string
    {
        $node = $xpath->query($query)?->item(0);
        return trim(preg_replace('/\s+/u', ' ', $node?->textContent ?? '') ?? '');
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            throw new RuntimeException('Não foi possível interpretar o HTML do Vagas.com.');
        }

        return new DOMXPath($document);
    }

    private function absoluteUrl(string $url): ?string
    {
        if (str_starts_with($url, 'https://') || str_starts_with($url, 'http://')) {
            return $url;
        }
        if (str_starts_with($url, '/')) {
            return 'https://www.vagas.com.br' . $url;
        }
        return null;
    }
}
