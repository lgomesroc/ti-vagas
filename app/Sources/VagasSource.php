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
        private readonly int $maxJobs = 200,
        ?VacancyLocationFilter $locationFilter = null,
    ) {
        $this->locationFilter = $locationFilter ?? new VacancyLocationFilter();
    }

    public function name(): string
    {
        return 'vagas:' . $this->companyKey;
    }

    public function fetch(): iterable
    {
        $html = $this->getPage($this->url);
        $xpath = $this->xpath($html);
        $links = $xpath->query('//a[contains(@href, "/oportunidade/")]');
        if ($links === false) {
            return [];
        }

        $details = [];
        foreach ($links as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }

            $detailUrl = $this->absoluteUrl($link->getAttribute('href'));
            if ($detailUrl === null) {
                continue;
            }

            $cardText = $this->cleanText($link->parentNode?->parentNode?->textContent ?? $link->textContent);
            if (!$this->matchesCompany($cardText)) {
                continue;
            }

            $details[$detailUrl] = true;
        }

        $vacancies = [];
        foreach (array_slice(array_keys($details), 0, $this->maxJobs) as $detailUrl) {
            try {
                $vacancy = $this->parseDetail($this->getPage($detailUrl), $detailUrl);
                if ($vacancy !== null) {
                    $vacancies[] = $vacancy;
                }
            } catch (\Throwable $exception) {
                Log::warning('Falha ao coletar oportunidade no Vagas.com.', [
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
            throw new RuntimeException('O Vagas.com retornou conteúdo vazio.');
        }

        return $html;
    }

    private function parseDetail(string $html, string $url): ?array
    {
        $xpath = $this->xpath($html);
        $title = $this->nodeText($xpath, '//h1');
        if ($title === '') {
            return null;
        }

        $description = $this->nodeText($xpath, '//*[contains(normalize-space(.), "Detalhes da vaga")]/following::*[1]');
        if ($description === '') {
            $description = $this->nodeText($xpath, '//main');
        }
        if ($description === '') {
            $description = $this->nodeText($xpath, '//body');
        }

        $publishedAt = $this->publicationDate($xpath, $html);
        if ($publishedAt === null) {
            Log::warning('Vagas.com: oportunidade sem data original de publicação verificável; ignorada.', [
                'company' => $this->companyName,
                'title' => $title,
                'url' => $url,
            ]);
            return null;
        }

        $location = $this->nodeText($xpath, '//*[contains(., "A empresa aceita candidaturas")]/preceding::*[1]');
        $body = $this->nodeText($xpath, '//body');
        if ($location === '') {
            $location = $this->extractLocation($body);
        }

        $workModel = $this->locationFilter->classifyWorkModel($title, $location, $description);
        if (!$this->locationFilter->isEligibleForCollection($location, $title, $description, $workModel)) {
            return null;
        }

        $id = basename(rtrim((string) parse_url($url, PHP_URL_PATH), '/'));
        return [
            'title' => $title,
            'company' => $this->companyName,
            'description' => $description,
            'location' => $location !== '' ? $location : null,
            'work_model' => $workModel,
            'employment_type' => null,
            'salary' => null,
            'application_url' => $url,
            'external_id' => $this->name() . ':' . Str::slug($id),
            'original_published_at' => $publishedAt,
        ];
    }

    private function matchesCompany(string $text): bool
    {
        $text = Str::lower($text);
        return match ($this->companyKey) {
            'sgr' => preg_match('/\bsgr\b/i', $text) === 1,
            'editora-globo' => str_contains($text, 'editora globo'),
            default => str_contains($text, Str::lower($this->companyName)),
        };
    }

    private function publicationDate(DOMXPath $xpath, string $html): ?string
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

        $text = $this->nodeText($xpath, '//body');
        if (preg_match('/(?:publicad[oa]|publicada em)\s*(\d{1,2}\/\d{1,2}\/\d{4})/iu', $text, $match)) {
            try {
                return CarbonImmutable::createFromFormat('!d/m/Y', $match[1], 'UTC')?->toIso8601String();
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function extractLocation(string $text): string
    {
        if (preg_match('/([A-ZÀ-Ú][\p{L} .-]+\s*\/\s*[A-Z]{2})/u', $text, $match)) {
            return trim($match[1]);
        }

        if (preg_match('/(100%\s*Home Office|Home Office|Remoto)/iu', $text, $match)) {
            return trim($match[1]);
        }

        return '';
    }

    private function nodeText(DOMXPath $xpath, string $query): string
    {
        $node = $xpath->query($query)?->item(0);
        return $this->cleanText($node?->textContent ?? '');
    }

    private function cleanText(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
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
        $url = trim($url);
        if (str_starts_with($url, 'https://') || str_starts_with($url, 'http://')) {
            return $url;
        }
        if (str_starts_with($url, '/')) {
            return 'https://trabalheconosco.vagas.com.br' . $url;
        }
        return null;
    }
}
