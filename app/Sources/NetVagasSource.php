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
use RuntimeException;\nuse Throwable;

class NetVagasSource implements VacancySource
{
    private const BASE_URL = 'https://www.netvagas.com.br';
    private const SEARCH_PATH = '/empresa/anuncios/cargo/';

    private array $stats = [];

    public function __construct(
        private readonly array $terms,
        private readonly int $maxPages = 2,
        private readonly int $initialLookbackDays = 60,
        private readonly int $requestDelayMs = 150,
        private readonly ?string $caBundle = null,
        private readonly ?VacancyLocationFilter $locationFilter = null,
    ) {
    }

    public function name(): string
    {
        return 'netvagas';
    }

    public function diagnostics(): array
    {
        return $this->stats;
    }

    public function fetch(): iterable
    {
        $terms = array_values(array_unique(array_filter(
            array_map(static fn ($term): string => trim((string) $term), $this->terms),
            static fn (string $term): bool => $term !== ''
        )));

        if ($terms === []) {
            throw new RuntimeException('Nenhum termo de busca da NetVagas foi configurado.');
        }

        $this->stats = [
            'Termos pesquisados' => count($terms),
            'Páginas consultadas' => 0,\n            'Falhas de consulta' => 0,
            'Anúncios encontrados' => 0,
            'Anúncios únicos' => 0,
            'Duplicados entre pesquisas' => 0,
            'Anúncios sem detalhes suficientes' => 0,
            'Anúncios fora dos últimos 60 dias' => 0,
            'Anúncios rejeitados por localização' => 0,
            'Anúncios enviados ao importador' => 0,
        ];

        $cutoff = CarbonImmutable::now('America/Sao_Paulo')
            ->subDays(max(1, $this->initialLookbackDays));
        $jobs = [];

        foreach ($terms as $term) {
            $slug = Str::slug($term);
            if ($slug === '') {
                continue;
            }

            $baseUrl = self::BASE_URL . self::SEARCH_PATH . $slug . '/';
            try {
                $firstHtml = $this->get($baseUrl);
            } catch (Throwable $exception) {
                $this->stats['Falhas de consulta']++;
                Log::warning('NetVagas: falha ao consultar termo de busca.', [
                    'term' => $term,
                    'message' => $exception->getMessage(),
                ]);
                continue;
            }

            $pages = [$baseUrl => $firstHtml];

            // Descobre os links reais de paginação publicados no HTML.
            // Não presume o nome do parâmetro nem constrói URLs de página.
            foreach ($this->paginationUrls($firstHtml, $baseUrl) as $pageUrl) {
                if (count($pages) >= max(1, $this->maxPages)) {
                    break;
                }
                if (isset($pages[$pageUrl])) {
                    continue;
                }
                try {
                    $pages[$pageUrl] = $this->get($pageUrl);
                } catch (Throwable $exception) {
                    $this->stats['Falhas de consulta']++;
                    Log::warning('NetVagas: falha ao consultar página de resultados.', [
                        'term' => $term,
                        'url' => $pageUrl,
                        'message' => $exception->getMessage(),
                    ]);
                    continue;
                }
                $this->delay();
            }

            foreach ($pages as $pageUrl => $html) {
                $this->stats['Páginas consultadas']++;
                $xpath = $this->xpath($html);
                $links = $xpath->query('//a[contains(@href, "/empresa/anuncios/a")]');

                if ($links === false) {
                    continue;
                }

                foreach ($links as $link) {
                    if (!$link instanceof DOMElement) {
                        continue;
                    }

                    $detailUrl = $this->absoluteUrl($link->getAttribute('href'));
                    if ($detailUrl === null || !preg_match('~/a(\d+)(?:/|$)~', (string) parse_url($detailUrl, PHP_URL_PATH), $idMatch)) {
                        continue;
                    }

                    $this->stats['Anúncios encontrados']++;
                    $externalId = 'netvagas:' . $idMatch[1];

                    if (isset($jobs[$externalId])) {
                        $this->stats['Duplicados entre pesquisas']++;
                        continue;
                    }

                    $card = $this->findCard($link);
                    if ($card === null) {
                        $this->stats['Anúncios sem detalhes suficientes']++;
                        continue;
                    }

                    $vacancy = $this->parseCard($card, $link, $detailUrl, $externalId, $cutoff);
                    if ($vacancy === null) {
                        continue;
                    }

                    $locationFilter = $this->locationFilter ?? new VacancyLocationFilter();
                    if (!$locationFilter->isEligibleForCollection(
                        $vacancy['location'],
                        $vacancy['title'],
                        $vacancy['description'],
                        $vacancy['work_model']
                    )) {
                        $this->stats['Anúncios rejeitados por localização']++;
                        continue;
                    }

                    $jobs[$externalId] = $vacancy;
                    $this->stats['Anúncios enviados ao importador']++;
                }

                $this->delay();
            }
        }

        $this->stats['Anúncios únicos'] = count($jobs);

        return array_values($jobs);
    }

    private function get(string $url): string
    {
        $response = Http::timeout(30)
            ->withOptions(['verify' => $this->caBundle ?: true])
            ->withUserAgent('Mozilla/5.0 (compatible; TI-Vagas/1.0; +https://github.com/lgomesroc/ti-vagas)')
            ->accept('text/html,application/xhtml+xml')
            ->withHeaders(['Accept-Language' => 'pt-BR,pt;q=0.9,en;q=0.8'])
            ->get($url);

        $response->throw();
        $html = $response->body();

        if (trim($html) === '') {
            throw new RuntimeException('A NetVagas retornou uma página vazia.');
        }

        return $html;
    }

    private function paginationUrls(string $html, string $baseUrl): array
    {
        $xpath = $this->xpath($html);
        $basePath = (string) parse_url($baseUrl, PHP_URL_PATH);
        $pages = [];

        foreach ($xpath->query('//a[@href]') ?: [] as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }

            $label = trim($link->textContent);
            if (!preg_match('/^\d+$/', $label) || (int) $label <= 1) {
                continue;
            }

            $url = $this->absoluteUrl($link->getAttribute('href'));
            if ($url === null || parse_url($url, PHP_URL_PATH) !== $basePath) {
                continue;
            }

            $pages[(int) $label] = $url;
        }

        ksort($pages);

        return array_values(array_unique($pages));
    }

    private function findCard(DOMElement $link): ?DOMElement
    {
        $node = $link;

        for ($level = 0; $level < 9 && $node instanceof DOMElement; $level++) {
            $text = $this->cleanText($node->textContent);
            if (
                mb_strlen($text) >= 100
                && mb_strlen($text) <= 30000
                && stripos($text, 'Descrição') !== false
                && stripos($text, 'Local de trabalho') !== false
            ) {
                return $node;
            }

            $node = $node->parentNode;
        }

        return null;
    }

    private function parseCard(
        DOMElement $card,
        DOMElement $titleLink,
        string $detailUrl,
        string $externalId,
        CarbonImmutable $cutoff
    ): ?array {
        $title = $this->cleanText($titleLink->textContent);
        $text = $this->cleanText($card->textContent);

        if ($title === '') {
            $this->stats['Anúncios sem detalhes suficientes']++;
            return null;
        }

        $publishedAt = $this->publishedAt($card, $text);
        if ($publishedAt === null || $publishedAt->lt($cutoff)) {
            $this->stats['Anúncios fora dos últimos 60 dias']++;
            return null;
        }

        $location = null;
        if (preg_match('/Local de trabalho\s*:\s*(.+?)(?=\s+via\s+[\p{L}0-9_-]+|\s+a\d{5,}|$)/iu', $text, $match)) {
            $location = trim($match[1], " \t\n\r\0\x0B.,;");
        }

        $workModel = null;
        if (preg_match('/\b(home office|remoto)\b/iu', $location ?? $text)) {
            $workModel = 'Remoto';
        } elseif (preg_match('/\bh[ií]brido\b/iu', $location ?? $text)) {
            $workModel = 'Híbrido';
        } elseif (preg_match('/\bpresencial\b/iu', $location ?? $text)) {
            $workModel = 'Presencial';
        }

        $company = $this->companyFromCard($card, $titleLink);
        $description = $this->descriptionFromCard($text);

        if ($company === null || $description === null || mb_strlen($description) < 80) {
            $this->stats['Anúncios sem detalhes suficientes']++;
            return null;
        }

        $employmentType = null;
        if (preg_match('/\b(CLT|PJ|tempor[aá]rio|terceir[oa])\b/iu', $text, $match)) {
            $employmentType = Str::upper($match[1]);
        }

        return [
            'title' => $title,
            'company' => $company,
            'description' => $description,
            'location' => $location,
            'work_model' => $workModel,
            'employment_type' => $employmentType,
            'salary' => null,
            // A página pública do anúncio é o destino de candidatura acessível
            // sem tentar extrair ou contornar links ATS reservados ao Premium.
            'application_url' => $detailUrl,
            'external_id' => $externalId,
            'original_published_at' => $publishedAt->toIso8601String(),
        ];
    }

    private function companyFromCard(DOMElement $card, DOMElement $titleLink): ?string
    {
        $links = $card->getElementsByTagName('a');
        $passedTitle = false;

        foreach ($links as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }
            if ($link->isSameNode($titleLink)) {
                $passedTitle = true;
                continue;
            }
            if (!$passedTitle) {
                continue;
            }

            $candidate = $this->cleanText($link->textContent);
            if (
                $candidate !== ''
                && mb_strlen($candidate) <= 160
                && !preg_match('/^(ver anúncio|descrição|próxima vaga|próxima)$/iu', $candidate)
                && !preg_match('/^\d+\s+vagas?$/iu', $candidate)
            ) {
                return $candidate;
            }
        }

        return null;
    }

    private function descriptionFromCard(string $text): ?string
    {
        $locationPosition = stripos($text, 'Local de trabalho');
        $beforeLocation = $locationPosition === false
            ? $text
            : substr($text, 0, $locationPosition);

        $descriptionPosition = strripos($beforeLocation, 'Descrição');
        if ($descriptionPosition === false) {
            return null;
        }

        $description = trim(substr($beforeLocation, $descriptionPosition + strlen('Descrição')));
        $description = preg_replace('/\s+via\s+[\p{L}0-9_-]+\s+a\d{5,}.*$/iu', '', $description) ?? $description;
        $description = preg_replace('/\s+/u', ' ', $description) ?? $description;

        return trim($description, " \t\n\r\0\x0B-:;|");
    }

    private function publishedAt(DOMElement $card, string $text): ?CarbonImmutable
    {
        foreach ($card->getElementsByTagName('time') as $time) {
            if (!$time instanceof DOMElement) {
                continue;
            }
            $datetime = trim($time->getAttribute('datetime'));
            if ($datetime !== '') {
                try {
                    return CarbonImmutable::parse($datetime, 'America/Sao_Paulo');
                } catch (\Throwable) {
                    // Tenta a data relativa visível no cartão.
                }
            }
        }

        $now = CarbonImmutable::now('America/Sao_Paulo');
        $normalized = Str::ascii(mb_strtolower($text));

        if (preg_match('/\bhoje\b/u', $normalized)) {
            return $now;
        }
        if (preg_match('/\bontem\b/u', $normalized)) {
            return $now->subDay();
        }
        if (preg_match('/\bha\s+(\d+)\s+minuto/u', $normalized, $match)) {
            return $now->subMinutes((int) $match[1]);
        }
        if (preg_match('/\bha\s+(\d+)\s+hora/u', $normalized, $match)) {
            return $now->subHours((int) $match[1]);
        }
        if (preg_match('/\bha\s+(\d+)\s+dias?/u', $normalized, $match)) {
            return $now->subDays((int) $match[1]);
        }
        if (preg_match('/\bha\s+(\d+)\s+semanas?/u', $normalized, $match)) {
            return $now->subWeeks((int) $match[1]);
        }
        if (preg_match('/\bha\s+(\d+)\s+mes(?:es)?/u', $normalized, $match)) {
            return $now->subMonths((int) $match[1]);
        }
        if (preg_match('/\b(\d{2})\/(\d{2})\/(\d{4})\b/u', $text, $match)) {
            try {
                return CarbonImmutable::createFromFormat(
                    '!d/m/Y',
                    $match[0],
                    'America/Sao_Paulo'
                );
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function xpath(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    private function absoluteUrl(string $href): ?string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, 'javascript:') || str_starts_with($href, '#')) {
            return null;
        }

        if (str_starts_with($href, '//')) {
            return 'https:' . $href;
        }
        if (str_starts_with($href, '/')) {
            return self::BASE_URL . $href;
        }
        if (filter_var($href, FILTER_VALIDATE_URL) !== false) {
            return $href;
        }

        return null;
    }

    private function cleanText(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    private function delay(): void
    {
        if ($this->requestDelayMs > 0) {
            usleep($this->requestDelayMs * 1000);
        }
    }
}
