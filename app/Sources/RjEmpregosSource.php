<?php

namespace App\\Sources;

use App\\Contracts\\VacancySource;
use App\\Services\\VacancyLocationFilter;
use Carbon\\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\\Support\\Facades\\Http;
use Illuminate\\Support\\Facades\\Log;
use Illuminate\\Support\\Str;
use RuntimeException;

class RjEmpregosSource implements VacancySource
{
    private readonly VacancyLocationFilter $locationFilter;

    public function __construct(
        private readonly string $baseUrl = 'https://rjempregos.net',
        private readonly ?string $caBundle = null,
        private readonly int $maxPages = 5,
        private readonly int $maxJobs = 50,
        ?VacancyLocationFilter $locationFilter = null,
    ) {
        $this->locationFilter = $locationFilter ?? new VacancyLocationFilter();
    }

    public function name(): string
    {
        return 'rjempregos';
    }

    public function fetch(): iterable
    {
        $vacancies = [];
        $seen = [];

        for ($page = 1; $page <= $this->maxPages && count($vacancies) < $this->maxJobs; $page++) {
            $feedUrl = rtrim($this->baseUrl, '/') . '/feed/';
            if ($page > 1) {
                $feedUrl .= '?paged=' . $page;
            }

            $items = $this->parseFeed($this->get($feedUrl, 'application/rss+xml, application/xml, text/xml'));

            if ($items === []) {
                break;
            }

            foreach ($items as $item) {
                if (count($vacancies) >= $this->maxJobs) {
                    break;
                }

                $articleUrl = trim($item['link'] ?? '');
                if (
                    $articleUrl === ''
                    || isset($seen[$articleUrl])
                    || !$this->isHttpUrl($articleUrl)
                ) {
                    continue;
                }

                $seen[$articleUrl] = true;

                try {
                    $vacancy = $this->parseArticle(
                        $this->get($articleUrl, 'text/html,application/xhtml+xml'),
                        $articleUrl,
                        $item
                    );

                    if ($vacancy !== null) {
                        $vacancies[] = $vacancy;
                    }
                } catch (\\Throwable $exception) {
                    Log::warning('Falha ao coletar anúncio no RJ Empregos.', [
                        'url' => $articleUrl,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }

        return $vacancies;
    }

    private function get(string $url, string $accept): string
    {
        $response = Http::timeout(40)
            ->withOptions(['verify' => $this->caBundle ?: true])
            ->withHeaders([
                'User-Agent' => 'TI-Vagas/1.0 (+https://github.com/lgomesroc/ti-vagas)',
                'Accept' => $accept,
            ])
            ->get($url);

        $response->throw();
        $body = $response->body();

        if (trim($body) === '') {
            throw new RuntimeException('O RJ Empregos retornou conteúdo vazio.');
        }

        return $body;
    }

    private function parseFeed(string $xml): array
    {
        $feed = simplexml_load_string($xml, \\SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);

        if (!$feed instanceof \\SimpleXMLElement) {
            throw new RuntimeException('Não foi possível interpretar o feed RSS do RJ Empregos.');
        }

        $items = [];
        foreach ($feed->channel->item ?? [] as $item) {
            $link = trim((string) ($item->link ?? ''));
            $publishedAt = trim((string) ($item->pubDate ?? ''));

            if ($link === '' || $publishedAt === '') {
                continue;
            }

            try {
                $publishedAt = CarbonImmutable::parse($publishedAt, 'UTC')->toIso8601String();
            } catch (\\Throwable) {
                continue;
            }

            $items[] = [
                'link' => $link,
                'title' => trim((string) ($item->title ?? '')),
                'guid' => trim((string) ($item->guid ?? '')),
                'published_at' => $publishedAt,
            ];
        }

        return $items;
    }

    private function parseArticle(string $html, string $url, array $item): ?array
    {
        $xpath = $this->xpath($html);
        $title = $this->nodeText($xpath, '//article//h1 | //main//h1 | //h1');
        $title = $title !== '' ? $title : ($item['title'] ?? '');

        $article = $xpath->query('//article')?->item(0)
            ?? $xpath->query('//main')?->item(0)
            ?? $xpath->query('//body')?->item(0);

        $content = $this->cleanText($article?->textContent ?? '');
        if ($title === '' || $content === '') {
            return null;
        }

        $company = $this->extractField(
            $content,
            'Empresa:',
            ['Ramo de atuação:', 'Descrição da vaga:', 'Cargo:', 'Local:', 'Salário:', 'Benefícios:', 'Requisitos:']
        );

        $applicationUrl = $this->applicationUrl($xpath);
        if ($company === '' || $applicationUrl === null) {
            return null;
        }

        $location = $this->extractField(
            $content,
            'Local:',
            ['Os interessados', 'Gostou da vaga', 'Candidatar-se', 'Candidate-se', 'Salário:', 'Benefícios:', 'Regime de contratação:', 'Horário de trabalho:', 'Modelo de trabalho:']
        );

        if ($location === '') {
            $location = $this->extractField(
                $content,
                'Localização:',
                ['Os interessados', 'Gostou da vaga', 'Candidatar-se', 'Candidate-se', 'Salário:', 'Benefícios:', 'Regime de contratação:', 'Horário de trabalho:', 'Modelo de trabalho:']
            );
        }

        $workModel = $this->locationFilter->classifyWorkModel($title, $location, $content);
        if (!$this->locationFilter->isEligibleForCollection($location, $title, $content, $workModel)) {
            return null;
        }

        $employmentType = $this->extractField(
            $content,
            'Regime de contratação:',
            ['Horário de trabalho:', 'Modelo de trabalho:', 'Local:', 'Os interessados']
        );

        if ($employmentType === '') {
            $employmentType = $this->extractField(
                $content,
                'Contratação:',
                ['Horário de trabalho:', 'Modelo de trabalho:', 'Local:', 'Os interessados']
            );
        }

        $salary = $this->extractField(
            $content,
            'Salário:',
            ['Benefícios:', 'Regime de contratação:', 'Horário de trabalho:', 'Modelo de trabalho:', 'Local:']
        );

        $externalId = $item['guid'] ?? '';
        if ($externalId === '' || filter_var($externalId, FILTER_VALIDATE_URL)) {
            $externalId = basename(rtrim((string) parse_url($url, PHP_URL_PATH), '/'));
        }

        return [
            'title' => $title,
            'company' => $company,
            'description' => $content,
            'location' => $location !== '' ? $location : null,
            'work_model' => $workModel,
            'employment_type' => $employmentType !== '' ? $employmentType : null,
            'salary' => $salary !== '' ? $salary : null,
            'application_url' => $applicationUrl,
            'external_id' => $this->name() . ':' . Str::slug($externalId),
            'original_published_at' => $item['published_at'],
        ];
    }

    private function applicationUrl(DOMXPath $xpath): ?string
    {
        $nodes = $xpath->query('//article//a[@href] | //main//a[@href] | //a[@href]');
        if ($nodes === false) {
            return null;
        }

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $label = Str::lower($this->cleanText($node->textContent));
            if (
                !str_contains($label, 'candidatar')
                && !str_contains($label, 'candidate-se')
                && !str_contains($label, 'inscreva-se')
                && !str_contains($label, 'inscrever-se')
                && !str_contains($label, 'apply')
            ) {
                continue;
            }

            $href = trim($node->getAttribute('href'));
            if (!$this->isHttpUrl($href)) {
                continue;
            }

            $host = Str::lower((string) parse_url($href, PHP_URL_HOST));
            if (in_array($host, ['rjempregos.net', 'www.rjempregos.net'], true)) {
                continue;
            }

            return $href;
        }

        return null;
    }

    private function extractField(string $text, string $label, array $stopLabels): string
    {
        $quotedStops = array_map(
            static fn (string $stop): string => preg_quote($stop, '/'),
            $stopLabels
        );

        $lookahead = $quotedStops === []
            ? '$'
            : '(?=\\s+(?:' . implode('|', $quotedStops) . ')|$)';

        $pattern = '/' . preg_quote($label, '/') . '\\s*(.+?)' . $lookahead . '/iu';
        if (!preg_match($pattern, $text, $match)) {
            return '';
        }

        return trim($match[1], " \\t\\n\\r\\0\\x0B:-");
    }

    private function isHttpUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(Str::lower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    private function nodeText(DOMXPath $xpath, string $query): string
    {
        return $this->cleanText($xpath->query($query)?->item(0)?->textContent ?? '');
    }

    private function cleanText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\\s+/u', ' ', $text) ?? '');
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
            throw new RuntimeException('Não foi possível interpretar o HTML do RJ Empregos.');
        }

        return new DOMXPath($document);
    }
}
