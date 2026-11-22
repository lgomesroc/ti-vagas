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

class BneSource implements VacancySource
{
    public function __construct(
        private readonly array $terms,
        private readonly int $maxPages = 3,
        private readonly int $maxJobs = 250,
        private readonly int $initialLookbackDays = 60,
        private readonly int $requestDelayMs = 200,
        private readonly ?string $caBundle = null,
        private readonly ?VacancyLocationFilter $locationFilter = null,
        private readonly string $baseUrl = 'https://www.bne.com.br',
    ) {
    }

    public function name(): string
    {
        return 'bne';
    }

    public function fetch(): iterable
    {
        $terms = array_values(array_unique(array_filter(array_map(
            static fn ($term) => trim((string) $term),
            $this->terms
        ))));

        if ($terms === []) {
            throw new RuntimeException('Nenhum termo de pesquisa foi configurado para o BNE.');
        }

        $cutoff = CarbonImmutable::now('America/Sao_Paulo')->subDays(max(1, $this->initialLookbackDays));
        $jobs = [];
        $seen = [];

        foreach ($terms as $term) {
            if (count($jobs) >= $this->maxJobs) {
                break;
            }

            $searchUrl = rtrim($this->baseUrl, '/') . '/vagas-de-emprego-para-' . Str::slug($term) . '/acesso';
            try {
                $html = $this->get($searchUrl);
            } catch (\Throwable $exception) {
                Log::warning('BNE: falha ao consultar termo.', [
                    'term' => $term,
                    'message' => $exception->getMessage(),
                ]);
                continue;
            }

            $pages = [$searchUrl => $html];
            foreach ($this->paginationUrls($html, $searchUrl) as $pageUrl) {
                if (count($pages) >= max(1, $this->maxPages)) {
                    break;
                }
                if (isset($pages[$pageUrl])) {
                    continue;
                }
                try {
                    $pages[$pageUrl] = $this->get($pageUrl);
                } catch (\Throwable $exception) {
                    Log::warning('BNE: falha ao consultar paginação.', [
                        'url' => $pageUrl,
                        'message' => $exception->getMessage(),
                    ]);
                }
                $this->delay();
            }

            foreach ($pages as $pageHtml) {
                $xpath = $this->xpath($pageHtml);
                $links = $xpath->query('//a[@href]');
                if ($links === false) {
                    continue;
                }

                foreach ($links as $link) {
                    if (!$link instanceof DOMElement) {
                        continue;
                    }

                    $href = $this->absoluteUrl($link->getAttribute('href'));
                    if ($href === null || !$this->isVacancyUrl($href) || isset($seen[$href])) {
                        continue;
                    }
                    $seen[$href] = true;

                    try {
                        $job = $this->parseDetail($this->get($href), $href, $cutoff);
                        if ($job === null) {
                            continue;
                        }

                        $filter = $this->locationFilter ?? new VacancyLocationFilter();
                        if (!$filter->isEligibleForCollection(
                            $job['location'],
                            $job['title'],
                            $job['description'],
                            $job['work_model']
                        )) {
                            continue;
                        }

                        $jobs[$job['external_id']] = $job;
                    } catch (\Throwable $exception) {
                        Log::warning('BNE: falha ao interpretar vaga.', [
                            'url' => $href,
                            'message' => $exception->getMessage(),
                        ]);
                    }

                    if (count($jobs) >= $this->maxJobs) {
                        break 2;
                    }
                    $this->delay();
                }
            }
        }

        return array_values($jobs);
    }

    private function parseDetail(string $html, string $url, CarbonImmutable $cutoff): ?array
    {
        $xpath = $this->xpath($html);
        $title = $this->nodeText($xpath, '//main//h1 | //h1');
        if ($title === '') {
            return null;
        }

        $body = $this->cleanText($xpath->query('//main')?->item(0)?->textContent
            ?? $xpath->query('//body')?->item(0)?->textContent
            ?? '');

        $published = $this->publishedAt($xpath, $body);
        if ($published !== null && $published->lt($cutoff)) {
            return null;
        }

        $company = $this->field($body, 'Empresa', ['Local', 'Modalidade', 'Contrato', 'Descrição Geral']);
        $location = $this->field($body, 'Local', ['Modalidade', 'Contrato', 'Descrição Geral']);
        $workModel = $this->field($body, 'Modalidade', ['Contrato', 'Descrição Geral']);
        $employmentType = $this->field($body, 'Contrato', ['Descrição Geral']);
        $salary = $this->field($body, 'Salário', ['Empresa', 'Local', 'Modalidade', 'Contrato', 'Descrição Geral']);
        $description = $this->description($body);

        if ($company === '') {
            $company = 'Não informada';
        }
        if ($description === '' || !$this->isTechnologyVacancy($title, $description)) {
            return null;
        }

        $workModel = $this->normalizeWorkModel($workModel, $location, $description);
        $externalId = basename(rtrim((string) parse_url($url, PHP_URL_PATH), '/'));

        return [
            'title' => $title,
            'company' => $company,
            'description' => $description,
            'location' => $location !== '' ? $location : null,
            'work_model' => $workModel,
            'employment_type' => $employmentType !== '' ? $employmentType : null,
            'salary' => $salary !== '' ? $salary : null,
            'application_url' => $url,
            'external_id' => 'bne:' . Str::slug($externalId),
            'original_published_at' => $published?->toIso8601String(),
        ];
    }

    private function description(string $body): string
    {
        if (!preg_match('/Descrição Geral\s*(.+?)(?=Esta vaga aparece também em|Vagas de emprego onde você estiver|$)/iu', $body, $match)) {
            return '';
        }
        return trim($match[1]);
    }

    private function field(string $text, string $label, array $stops): string
    {
        $stopPattern = implode('|', array_map(static fn ($value) => preg_quote($value, '/'), $stops));
        $pattern = '/\b' . preg_quote($label, '/') . '\s*:\s*(.+?)(?=\s+(?:' . $stopPattern . ')\s*:|\s+Descrição Geral|$)/iu';
        return preg_match($pattern, $text, $match)
            ? trim($match[1], " \t\n\r\0\x0B:-")
            : '';
    }

    private function normalizeWorkModel(string $value, string $location, string $description): ?string
    {
        $value = Str::lower($value . ' ' . $location);
        if (preg_match('/home\s*office|remoto/iu', $value)) {
            return 'Remoto';
        }
        if (preg_match('/h[ií]brido/iu', $value)) {
            return 'Híbrido';
        }
        if (preg_match('/presencial/iu', $value)) {
            return 'Presencial';
        }
        if (preg_match('/home\s*office|remoto/iu', $description)) {
            return 'Remoto';
        }
        return null;
    }

    private function isTechnologyVacancy(string $title, string $description): bool
    {
        return (bool) preg_match(
            '/\b(desenvolvedor|desenvolvedora|programador|programadora|software|sistemas|php|laravel|\.net|c#|java|python|javascript|typescript|backend|back-end|frontend|front-end|full.?stack|suporte t[eé]cnico|analista de suporte|infraestrutura de ti|redes de computadores|devops|cloud|qa|quality assurance|teste de software|seguran[cç]a da informa[cç]ão|banco de dados|dados|noc|soc)\b/iu',
            $title . ' ' . $description
        );
    }

    private function publishedAt(DOMXPath $xpath, string $body): ?CarbonImmutable
    {
        $value = $xpath->query('//time[@datetime]')?->item(0)?->getAttribute('datetime')
            ?? $xpath->query('//meta[@property="article:published_time"]')?->item(0)?->getAttribute('content');

        if ($value === '' || $value === null) {
            if (preg_match('/Publicado(?: em)?\s*(\d{2}\/\d{2}\/\d{4})/iu', $body, $match)) {
                $value = $match[1];
            }
        }

        if (!$value) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'America/Sao_Paulo');
        } catch (\Throwable) {
            return null;
        }
    }

    private function paginationUrls(string $html, string $baseUrl): array
    {
        $xpath = $this->xpath($html);
        $urls = [];
        foreach ($xpath->query('//a[@href]') ?: [] as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }
            $label = trim($link->textContent);
            if (!preg_match('/^\d+$/', $label) || (int) $label < 2) {
                continue;
            }
            $url = $this->absoluteUrl($link->getAttribute('href'));
            if ($url !== null && str_starts_with($url, rtrim($baseUrl, '/'))) {
                $urls[(int) $label] = $url;
            }
        }
        ksort($urls);
        return array_values(array_unique($urls));
    }

    private function isVacancyUrl(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        return str_contains($path, '/vaga-de-emprego-na-area-informatica-')
            || preg_match('~/jobs/[^/]+/vaga-de-emprego~i', $path) === 1;
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
            throw new RuntimeException('BNE retornou conteúdo vazio.');
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

    private function absoluteUrl(string $href): ?string
    {
        $href = trim($href);
        if (str_starts_with($href, '//')) {
            return 'https:' . $href;
        }
        if (str_starts_with($href, '/')) {
            return rtrim($this->baseUrl, '/') . $href;
        }
        return filter_var($href, FILTER_VALIDATE_URL) ? $href : null;
    }

    private function delay(): void
    {
        if ($this->requestDelayMs > 0) {
            usleep($this->requestDelayMs * 1000);
        }
    }
}
