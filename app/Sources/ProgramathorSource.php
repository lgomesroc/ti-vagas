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
use Throwable;

class ProgramathorSource implements VacancySource
{
    private const BASE_URL = 'https://programathor.com.br';
    private const LISTING_URL = self::BASE_URL . '/jobs-api';

    public function __construct(
        private readonly ?string $caBundle = null,
        private readonly int $maxPages = 5,
        private readonly int $maxJobs = 150,
        private readonly int $initialLookbackDays = 60,
        private readonly int $requestDelayMs = 200,
        private readonly ?VacancyLocationFilter $locationFilter = null,
    ) {
    }

    public function name(): string
    {
        return 'programathor';
    }

    public function fetch(): iterable
    {
        $cutoff = CarbonImmutable::now('America/Sao_Paulo')
            ->subDays(max(1, $this->initialLookbackDays));
        $pages = [];
        $jobs = [];
        $seenIds = [];

        try {
            $firstPage = $this->get(self::LISTING_URL);
        } catch (Throwable $exception) {
            Log::warning('ProgramaThor: falha ao consultar a listagem.', [
                'message' => $exception->getMessage(),
            ]);

            return [];
        }

        $pages[self::LISTING_URL] = $firstPage;

        foreach ($this->paginationUrls($firstPage) as $pageUrl) {
            if (count($pages) >= max(1, $this->maxPages)) {
                break;
            }

            try {
                $pages[$pageUrl] = $this->get($pageUrl);
            } catch (Throwable $exception) {
                Log::warning('ProgramaThor: falha ao consultar página.', [
                    'url' => $pageUrl,
                    'message' => $exception->getMessage(),
                ]);
            }

            $this->delay();
        }

        foreach ($pages as $html) {
            foreach ($this->vacancyUrls($html) as $vacancyUrl) {
                $id = $this->vacancyId($vacancyUrl);

                if ($id === null || isset($seenIds[$id])) {
                    continue;
                }

                $seenIds[$id] = true;

                try {
                    $vacancy = $this->parseDetail(
                        $this->get($vacancyUrl),
                        $vacancyUrl,
                        $id,
                        $cutoff
                    );
                } catch (Throwable $exception) {
                    Log::warning('ProgramaThor: falha ao interpretar vaga.', [
                        'url' => $vacancyUrl,
                        'message' => $exception->getMessage(),
                    ]);

                    $this->delay();
                    continue;
                }

                if ($vacancy !== null) {
                    $filter = $this->locationFilter ?? new VacancyLocationFilter();

                    if ($filter->isEligibleForCollection(
                        $vacancy['location'],
                        $vacancy['title'],
                        $vacancy['description'],
                        $vacancy['work_model']
                    )) {
                        $jobs[$vacancy['external_id']] = $vacancy;
                    }
                }

                if (count($jobs) >= max(1, $this->maxJobs)) {
                    break 2;
                }

                $this->delay();
            }
        }

        return array_values($jobs);
    }

    private function parseDetail(
        string $html,
        string $url,
        string $id,
        CarbonImmutable $cutoff
    ): ?array {
        $xpath = $this->xpath($html);
        $title = $this->nodeText($xpath, '//main//h1 | //h1');
        $body = $this->cleanText(
            $xpath->query('//main')?->item(0)?->textContent
                ?? $xpath->query('//body')?->item(0)?->textContent
                ?? ''
        );

        if (
            $title === ''
            || preg_match('/^\s*VAGA\s+EXTERNA\b/iu', $title)
            || preg_match('/\bVAGA\s+EXTERNA\b/iu', $body)
            || preg_match('/\b(vaga vencida|oportunidade vencida|não recebe mais candidaturas)\b/iu', $body)
        ) {
            return null;
        }

        $publishedAt = $this->publishedAt($xpath, $body);
        if ($publishedAt === null || $publishedAt->lt($cutoff)) {
            return null;
        }

        $company = $this->companyName($xpath, $title);
        if ($company === '') {
            return null;
        }

        $description = $this->description($body);
        if (mb_strlen($description) < 80) {
            return null;
        }

        $location = $this->field($body, '/Localiza[cç][ãa]o\s*:/iu');
        $workModel = $this->workModel($body, $location);
        $employmentType = $this->employmentType($body);
        $salary = $this->salary($body);

        return [
            'title' => $title,
            'company' => $company,
            'description' => $description,
            'location' => $location !== '' ? $location : null,
            'work_model' => $workModel,
            'employment_type' => $employmentType,
            'salary' => $salary,
            // A candidatura é feita no próprio ProgramaThor, após login do candidato.
            'application_url' => $url,
            'external_id' => 'programathor:' . $id,
            'original_published_at' => $publishedAt->toIso8601String(),
        ];
    }

    private function description(string $body): string
    {
        $start = null;

        foreach (['Atividades e Responsabilidades', 'Descrição da vaga', 'Descrição'] as $heading) {
            $position = mb_stripos($body, $heading);
            if ($position !== false) {
                $start = $position + mb_strlen($heading);
                break;
            }
        }

        if ($start === null) {
            return '';
        }

        $description = mb_substr($body, $start);

        foreach (['Seu perfil combina em', 'Veja vagas similares:', 'Não perca nenhuma oportunidade!'] as $stop) {
            $position = mb_stripos($description, $stop);
            if ($position !== false) {
                $description = mb_substr($description, 0, $position);
            }
        }

        return trim($description);
    }

    private function companyName(DOMXPath $xpath, string $title): string
    {
        $nodes = $xpath->query('//h1/following::a[@href]');
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                if (!$node instanceof DOMElement) {
                    continue;
                }

                $candidate = $this->cleanText($node->textContent);
                $href = strtolower($node->getAttribute('href'));

                if (
                    $candidate !== ''
                    && $candidate !== $title
                    && !preg_match('/^(api|clt|pj|estágio|júnior|pleno|sênior|remoto)$/iu', $candidate)
                    && !preg_match('/(entrar|cadastrar|candidatar|vaga|jobs)/iu', $href)
                ) {
                    return $candidate;
                }
            }
        }

        return '';
    }

    private function field(string $body, string $labelPattern): string
    {
        if (!preg_match(
            '/' . $labelPattern . '\s*(.+?)(?=\s+(?:Sal[aá]rio|Localiza[cç][ãa]o|N[ií]vel|Home Office|Remoto|Presencial|H[ií]brido|CLT|PJ)\b|$)/iu',
            $body,
            $match
        )) {
            return '';
        }

        return trim($match[1], " \t\n\r\0\x0B:-");
    }

    private function workModel(string $body, string $location): ?string
    {
        $text = Str::lower($location . ' ' . $body);

        if (preg_match('/home\s*office|\bremoto\b/iu', $text)) {
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

    private function employmentType(string $body): ?string
    {
        if (preg_match('/\bCLT\s*\/\s*PJ\b|\bPJ\s*\/\s*CLT\b/iu', $body)) {
            return 'CLT / PJ';
        }
        if (preg_match('/\bCLT\b/iu', $body)) {
            return 'CLT';
        }
        if (preg_match('/\bPJ\b/iu', $body)) {
            return 'PJ';
        }

        return null;
    }

    private function salary(string $body): ?string
    {
        return preg_match('/Sal[aá]rio\s*:\s*(.+?)(?=\s+(?:J[uú]nior|Pleno|S[eê]nior|Aceita candidatos|Atividades e Responsabilidades)\b|$)/iu', $body, $match)
            ? trim($match[1])
            : null;
    }

    private function publishedAt(DOMXPath $xpath, string $body): ?CarbonImmutable
    {
        $value = $xpath->query('//time[@datetime]')?->item(0)?->getAttribute('datetime')
            ?? $xpath->query('//meta[@property="article:published_time"]')?->item(0)?->getAttribute('content');

        if (!$value && preg_match('/Publicada em\s*(\d{2}\/\d{2}\/\d{4})/iu', $body, $match)) {
            $value = $match[1];
        }

        if (!$value) {
            return null;
        }

        try {
            if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $value)) {
                return CarbonImmutable::createFromFormat('!d/m/Y', $value, 'America/Sao_Paulo');
            }

            return CarbonImmutable::parse($value, 'America/Sao_Paulo');
        } catch (Throwable) {
            return null;
        }
    }

    private function paginationUrls(string $html): array
    {
        $xpath = $this->xpath($html);
        $pages = [];

        foreach ($xpath->query('//a[@href]') ?: [] as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }

            $href = trim($link->getAttribute('href'));
            $label = $this->cleanText($link->textContent);

            if (!preg_match('/^\d+$/', $label) || (int) $label <= 1) {
                continue;
            }

            $url = $this->absoluteUrl($href);
            if (
                $url !== null
                && parse_url($url, PHP_URL_HOST) === 'programathor.com.br'
                && parse_url($url, PHP_URL_PATH) === '/jobs-api'
                && str_contains($url, 'page=')
            ) {
                $pages[(int) $label] = $url;
            }
        }

        ksort($pages);

        return array_values(array_unique($pages));
    }

    private function vacancyUrls(string $html): array
    {
        $xpath = $this->xpath($html);
        $urls = [];

        foreach ($xpath->query('//a[@href]') ?: [] as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }

            $url = $this->absoluteUrl($link->getAttribute('href'));
            if ($url !== null && $this->vacancyId($url) !== null) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    private function vacancyId(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return preg_match('~^/jobs/(\d+)(?:-|/|$)~', $path, $match)
            ? $match[1]
            : null;
    }

    private function absoluteUrl(string $href): ?string
    {
        $href = trim($href);

        if (str_starts_with($href, '/')) {
            return self::BASE_URL . $href;
        }

        if (filter_var($href, FILTER_VALIDATE_URL) !== false) {
            $host = strtolower((string) parse_url($href, PHP_URL_HOST));

            return $host === 'programathor.com.br' || $host === 'www.programathor.com.br'
                ? $href
                : null;
        }

        return null;
    }

    private function get(string $url): string
    {
        $response = Http::timeout(35)
            ->withOptions(['verify' => $this->caBundle ?: true])
            ->withUserAgent('Mozilla/5.0 (compatible; TI-Vagas/1.0; +https://github.com/lgomesroc/ti-vagas)')
            ->accept('text/html,application/xhtml+xml')
            ->withHeaders(['Accept-Language' => 'pt-BR,pt;q=0.9,en;q=0.8'])
            ->get($url);

        $response->throw();

        if (trim($response->body()) === '') {
            throw new \RuntimeException('ProgramaThor retornou uma página vazia.');
        }

        return $response->body();
    }

    private function xpath(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    private function nodeText(DOMXPath $xpath, string $query): string
    {
        return $this->cleanText($xpath->query($query)?->item(0)?->textContent ?? '');
    }

    private function cleanText(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    private function delay(): void
    {
        if ($this->requestDelayMs > 0) {
            usleep($this->requestDelayMs * 1000);
        }
    }
}
