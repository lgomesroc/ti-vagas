<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Services\VacancyLocationFilter;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class NerdinSource implements VacancySource
{
    private const BASE_URL = 'https://www.nerdin.com.br';
    private const LIST_URL = self::BASE_URL . '/vagas.php';

    private readonly VacancyLocationFilter $locationFilter;
    private array $stats = [];

    public function diagnostics(): array
    {
        return $this->stats;
    }

    public function __construct(
        private readonly ?string $caBundle = null,
        private readonly int $maxPages = 45,
        private readonly int $maxJobs = 500,
        ?VacancyLocationFilter $locationFilter = null,
    ) {
        $this->locationFilter = $locationFilter ?? new VacancyLocationFilter();
    }

    public function name(): string
    {
        return 'nerdin';
    }

    public function fetch(): iterable
    {
        $vacancies = [];
        $seen = [];
        $detailRequests = 0;
        $this->stats = [
            'Páginas consultadas' => 0,
            'Links de vagas encontrados' => 0,
            'URLs únicas' => 0,
            'Cartões identificados' => 0,
            'Cartões sem leitura' => 0,
            'Vagas descartadas por relevância' => 0,
            'Detalhes consultados' => 0,
            'Detalhes aceitos' => 0,
            'Detalhes sem empresa' => 0,
            'Detalhes sem descrição' => 0,
            'Detalhes rejeitados por localização' => 0,
            'Exemplos rejeitados por localização' => '',
            'Detalhes sem data' => 0,
            'Páginas de detalhe indisponíveis' => 0,
            'Exceções na leitura' => 0,
            'Título HTML recebido' => '',
            'Quantidade de links HTML' => 0,
            'Links com texto vaga no href' => 0,
            'Exemplos de hrefs' => '',
            'Exemplos de links de vagas' => '',
        ];

        for ($page = 1; $page <= $this->maxPages && count($vacancies) < $this->maxJobs; $page++) {
            $url = self::LIST_URL . ($page > 1 ? '?pagina=' . $page : '');
            $this->stats['Páginas consultadas']++;
            $html = $this->get($url);
            $xpath = $this->xpath($html);
            $this->stats['Título HTML recebido'] = $this->nodeText($xpath, '//title');
            $allLinks = $xpath->query('//a[@href]');
            $this->stats['Quantidade de links HTML'] = $allLinks?->length ?? 0;
            $hrefSamples = [];
            $vacancyHrefSamples = [];
            foreach ($allLinks ?: [] as $htmlLink) {
                if (!$htmlLink instanceof DOMElement) {
                    continue;
                }
                $href = trim($htmlLink->getAttribute('href'));
                if (stripos($href, 'vaga') !== false) {
                    $this->stats['Links com texto vaga no href']++;
                    if (count($vacancyHrefSamples) < 15) {
                        $vacancyHrefSamples[] = $href;
                    }
                }
                if (count($hrefSamples) < 8) {
                    $hrefSamples[] = $href;
                }
            }
            $this->stats['Exemplos de hrefs'] = implode(' | ', $hrefSamples);
            $this->stats['Exemplos de links de vagas'] = implode(' | ', $vacancyHrefSamples);
            $links = $xpath->query('//a[contains(@href, "vaga_emprego/")]');

            if ($links === false || $links->length === 0) {
                break;
            }

            $this->stats['Links de vagas encontrados'] += $links->length;
            $pageIds = [];

            foreach ($links as $link) {
                if (!$link instanceof DOMElement) {
                    continue;
                }

                $detailUrl = $this->absoluteUrl($link->getAttribute('href'));
                if ($detailUrl === null || isset($seen[$detailUrl])) {
                    continue;
                }

                $seen[$detailUrl] = true;
                $this->stats['URLs únicas']++;
                $card = $this->findCard($link);
                if ($card === null) {
                    $this->stats['Cartões sem leitura']++;
                    continue;
                }

                $this->stats['Cartões identificados']++;
                $cardData = $this->parseCard($card);
                if ($cardData === null) {
                    $this->stats['Cartões sem leitura']++;
                    continue;
                }
                if (!$this->isPotentiallyRelevant($cardData)) {
                    $this->stats['Vagas descartadas por relevância']++;
                    continue;
                }

                $pageIds[] = $detailUrl;
                if ($detailRequests >= $this->maxJobs * 3) {
                    break 2;
                }
            }

            foreach ($pageIds as $detailUrl) {
                if (count($vacancies) >= $this->maxJobs) {
                    break 2;
                }

                try {
                    $card = $this->findCardByUrl($xpath, $detailUrl);
                    if ($card === null) {
                        continue;
                    }

                    $cardData = $this->parseCard($card);
                    if ($cardData === null) {
                        continue;
                    }

                    $detailRequests++;
                    $this->stats['Detalhes consultados']++;
                    $vacancy = $this->parseDetail($this->get($detailUrl), $detailUrl, $cardData);
                    if ($vacancy !== null) {
                        $vacancies[] = $vacancy;
                        $this->stats['Detalhes aceitos']++;
                    }
                } catch (\Throwable $exception) {
                    $this->stats['Exceções na leitura']++;
                    report($exception);
                }
            }
        }

        return $vacancies;
    }

    private function get(string $url): string
    {
        $response = Http::timeout(35)
            ->withOptions(['verify' => $this->caBundle ?: true])
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; TI-Vagas/1.0; +https://github.com/lgomesroc/ti-vagas)',
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'pt-BR,pt;q=0.9,en;q=0.8',
            ])
            ->get($url);

        $response->throw();
        $html = $response->body();

        if (trim($html) === '') {
            throw new RuntimeException('A Nerdin retornou uma página vazia.');
        }

        return $html;
    }

    private function findCard(DOMElement $link): ?DOMElement
    {
        $node = $link;
        for ($level = 0; $level < 7 && $node instanceof DOMElement; $level++) {
            if ($node->tagName === 'article' || $node->tagName === 'li') {
                return $node;
            }

            $headings = $node->getElementsByTagName('h2')->length
                + $node->getElementsByTagName('h3')->length
                + $node->getElementsByTagName('h4')->length;

            $text = $this->cleanText($node->textContent);
            if ($headings > 0 && mb_strlen($text) >= 40 && mb_strlen($text) <= 5000) {
                return $node;
            }

            $node = $node->parentNode;
        }

        return null;
    }

    private function findCardByUrl(DOMXPath $xpath, string $url): ?DOMElement
    {
        $links = $xpath->query('//a[contains(@href, "vaga_emprego/")]');
        foreach ($links ?: [] as $link) {
            if ($link instanceof DOMElement && $this->absoluteUrl($link->getAttribute('href')) === $url) {
                return $this->findCard($link);
            }
        }

        return null;
    }

    private function parseCard(DOMElement $card): ?array
    {
        $heading = null;
        foreach (['h2', 'h3', 'h4'] as $tag) {
            $nodes = $card->getElementsByTagName($tag);
            if ($nodes->length > 0) {
                $heading = $nodes->item(0);
                break;
            }
        }

        $title = $this->cleanText($heading?->textContent ?? '');
        if ($title === '') {
            return null;
        }

        $text = $this->cleanText($card->textContent);
        $company = $this->extractCompanyFromCard($card, $heading);
        $location = $this->extractLocation($text);
        $publishedAt = $this->parseRelativeDate($text);

        return [
            'title' => $title,
            'company' => $company,
            'location' => $location,
            'card_text' => $text,
            'published_at' => $publishedAt,
            'work_model' => $this->normalizeWorkModel($text),
            'employment_type' => $this->extractEmploymentType($text),
            'salary' => $this->extractSalary($text),
        ];
    }

    private function extractCompanyFromCard(DOMElement $card, ?DOMNode $heading): ?string
    {
        $nodes = $card->getElementsByTagName('*');
        $afterTitle = false;

        foreach ($nodes as $node) {
            if ($node === $heading) {
                $afterTitle = true;
                continue;
            }

            if (!$afterTitle || !$node instanceof DOMElement) {
                continue;
            }

            if (!in_array($node->tagName, ['p', 'span', 'strong', 'div'], true)) {
                continue;
            }

            $candidate = $this->cleanText($node->textContent);
            if ($candidate === '' || mb_strlen($candidate) > 100) {
                continue;
            }

            if (preg_match('/\b(CLT|PJ|freelancer|junior|júnior|jr\.?|pleno|senior|sênior|home office|remoto|híbrido|presencial|salário|publicado|há \d|cód\.)\b/iu', $candidate)) {
                continue;
            }

            if (preg_match('/\b(R\$|\d{1,2}:\d{2}|\d+ candidatos?)\b/iu', $candidate)) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    private function parseDetail(string $html, string $url, array $card): ?array
    {
        $xpath = $this->xpath($html);
        $body = $xpath->query('//main')?->item(0)
            ?? $xpath->query('//article')?->item(0)
            ?? $xpath->query('//body')?->item(0);

        $pageText = $this->cleanText($body?->textContent ?? '');
        if ($pageText === '' || preg_match('/no momento, n[aã]o estamos aceitando novas candidaturas|vaga n[aã]o est[aá] mais dispon[ií]vel|processo seletivo encerrado/iu', $pageText)) {
            $this->stats['Páginas de detalhe indisponíveis']++;
            return null;
        }

        $title = $this->nodeText($xpath, '//main//h1 | //article//h1 | //h1');
        if ($title === '') {
            $title = $card['title'];
        }

        $company = $card['company'];
        if ($company === null || $company === '') {
            $company = $this->extractCompanyFromDetail($xpath, $title);
        }

        if ($company === null || $company === '') {
            $this->stats['Detalhes sem empresa']++;
            return null;
        }

        $descriptionNode = $xpath->query('//main')?->item(0)
            ?? $xpath->query('//article')?->item(0)
            ?? $body;
        $description = $this->cleanText($descriptionNode?->textContent ?? '');
        if ($description === '') {
            $this->stats['Detalhes sem descrição']++;
            return null;
        }

        $location = $card['location'] ?: $this->extractLocation($pageText);
        $workModel = $card['work_model'] ?: $this->normalizeWorkModel($pageText);
        if ($workModel === 'Remoto' && ($location === null || $location === '')) {
            $location = 'Brasil (remoto)';
        }

        if (!$this->locationFilter->isEligibleForCollection(
            $location,
            $title,
            $description,
            $workModel
        )) {
            $this->stats['Detalhes rejeitados por localização']++;
            $samples = $this->stats['Exemplos rejeitados por localização'] === ''
                ? []
                : explode("\n", $this->stats['Exemplos rejeitados por localização']);
            if (count($samples) < 15) {
                $samples[] = implode(' | ', [
                    'Título: ' . $title,
                    'Local: ' . ($location ?: '(não identificado)'),
                    'Modelo: ' . ($workModel ?: '(não identificado)'),
                    'Descrição: ' . mb_substr($description, 0, 180),
                    'URL: ' . $url,
                ]);
                $this->stats['Exemplos rejeitados por localização'] = implode("\n", $samples);
            }
            return null;
        }

        $applicationUrl = $this->extractApplicationUrl($xpath, $url);
        $externalId = basename((string) parse_url($url, PHP_URL_PATH));
        if ($externalId === '') {
            return null;
        }

        $publishedAt = $card['published_at'];
        if ($publishedAt === null) {
            $publishedAt = $this->parseRelativeDate($pageText);
        }
        if ($publishedAt === null) {
            $this->stats['Detalhes sem data']++;
            return null;
        }

        return [
            'title' => $title,
            'company' => $company,
            'description' => $description,
            'location' => $location,
            'work_model' => $workModel,
            'employment_type' => $card['employment_type'] ?: $this->extractEmploymentType($pageText),
            'salary' => $card['salary'] ?: $this->extractSalary($pageText),
            'application_url' => $applicationUrl,
            'external_id' => 'nerdin:' . Str::slug($externalId),
            'original_published_at' => $publishedAt,
        ];
    }

    private function extractCompanyFromDetail(DOMXPath $xpath, string $title): ?string
    {
        $heading = $xpath->query('//h1')?->item(0);
        if (!$heading instanceof DOMElement) {
            return null;
        }

        $node = $heading->nextSibling;
        while ($node !== null) {
            $candidate = $this->cleanText($node->textContent ?? '');
            if ($candidate !== '' && mb_strlen($candidate) <= 120
                && !preg_match('/\b(CLT|PJ|Junior|Júnior|Pleno|Senior|Sênior|Home Office|Presencial|Híbrido|candidatos?)\b/iu', $candidate)) {
                return $candidate;
            }
            $node = $node->nextSibling;
        }

        return null;
    }

    private function extractApplicationUrl(DOMXPath $xpath, string $detailUrl): string
    {
        $links = $xpath->query('//a[@href]');
        foreach ($links ?: [] as $link) {
            if (!$link instanceof DOMElement) {
                continue;
            }

            $label = Str::lower($this->cleanText($link->textContent));
            if (!str_contains($label, 'candid') && !str_contains($label, 'enviar candidatura')) {
                continue;
            }

            $href = trim($link->getAttribute('href'));
            $absolute = $this->absoluteUrl($href);
            if ($absolute !== null && !str_contains($absolute, '/login') && !str_contains($absolute, '/cadastro')) {
                return $absolute;
            }

            if (filter_var($href, FILTER_VALIDATE_URL) !== false
                && in_array(Str::lower((string) parse_url($href, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                return $href;
            }
        }

        // A candidatura pode ser interna e exigir sessão. O detalhe público
        // é mantido como destino para que o candidato siga o fluxo da fonte.
        return $detailUrl;
    }

    private function isPotentiallyRelevant(array $card): bool
    {
        $text = Str::ascii(mb_strtolower($card['title'] . ' ' . $card['card_text']));
        if (preg_match('/\b(estagio|estagiario|intern|trainee|pleno|senior|sr\.?|staff|principal|lead|lider|gerente|manager|diretor|coordenador|especialista)\b/', $text)) {
            return false;
        }

        return preg_match('/desenvolvedor|desenvolvedora|developer|programador|software|backend|back.?end|frontend|front.?end|full.?stack|php|laravel|java|\.net|c#|python|javascript|typescript|angular|react|node|devops|infraestrutura|infra|cloud|suporte|service desk|help desk|redes|network|seguranca|cybersecurity|qa|quality assurance|testes|tester|banco de dados|database|dba|analista de sistemas|analista de dados|analista bi|engenheiro de software|dados|data|power bi|analytics|bi|sistemas|linux|windows server|noc|soc|tecnico de ti|t[eé]cnico em inform[aá]tica/i', $text) === 1;
    }

    private function extractLocation(string $text): ?string
    {
        if (preg_match('/\bHome Office\b|\bRemoto\b/iu', $text, $match)) {
            return 'Home Office';
        }

        if (preg_match('/([\p{L} .\'-]+\s*[•\/-]\s*[A-Z]{2})/u', $text, $match)) {
            return trim($match[1]);
        }

        return null;
    }

    private function normalizeWorkModel(string $text): ?string
    {
        if (preg_match('/\bhome office\b|\bremoto\b/iu', $text)) {
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

    private function extractEmploymentType(string $text): ?string
    {
        if (preg_match('/\b(CLT|PJ|Freelancer)\b/iu', $text, $match)) {
            return Str::upper($match[1]);
        }

        return null;
    }

    private function extractSalary(string $text): ?string
    {
        if (preg_match('/(?:R\$\s*[\d.]+(?:,\d{2})?(?:\s*(?:a|-)\s*R\$?\s*[\d.]+(?:,\d{2})?)?|at[eé]\s+R\$\s*[\d.]+(?:,\d{2})?)/iu', $text, $match)) {
            return trim($match[0]);
        }

        return null;
    }

    private function parseRelativeDate(string $text): ?string
    {
        $text = Str::ascii(mb_strtolower($text));
        $now = CarbonImmutable::now('UTC');

        if (preg_match('/publicado\s+agora|\ agora\b/', $text)) {
            return $now->toIso8601String();
        }
        if (preg_match('/ha\s+(\d+)\s+minuto/', $text, $match)) {
            return $now->subMinutes((int) $match[1])->toIso8601String();
        }
        if (preg_match('/ha\s+(\d+)\s+hora/', $text, $match)) {
            return $now->subHours((int) $match[1])->toIso8601String();
        }
        if (preg_match('/\bontem\b/', $text)) {
            return $now->subDay()->toIso8601String();
        }
        if (preg_match('/ha\s+(\d+)\s+dias?/', $text, $match)) {
            return $now->subDays((int) $match[1])->toIso8601String();
        }
        if (preg_match('/ha\s+(\d+)\s+semanas?/', $text, $match)) {
            return $now->subWeeks((int) $match[1])->toIso8601String();
        }
        if (preg_match('/publicado\s+em\s+(\d{2})\/(\d{2})\/(\d{4})/', $text, $match)) {
            try {
                return CarbonImmutable::createFromFormat('!d/m/Y', $match[1] . '/' . $match[2] . '/' . $match[3], 'UTC')?->toIso8601String();
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function absoluteUrl(string $url): ?string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($url === '') {
            return null;
        }
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }
        if (str_starts_with($url, '/')) {
            return self::BASE_URL . $url;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(Str::lower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            && Str::lower((string) parse_url($url, PHP_URL_HOST)) === 'www.nerdin.com.br') {
            return $url;
        }

        // A listagem da Nerdin fornece links relativos sem a barra inicial,
        // por exemplo: vaga_emprego/vaga-exemplo-123.php.
        if (!str_starts_with($url, '#')
            && !str_starts_with($url, '?')
            && !str_starts_with($url, 'javascript:')
            && parse_url($url, PHP_URL_SCHEME) === null
            && parse_url($url, PHP_URL_HOST) === null) {
            return self::BASE_URL . '/' . ltrim($url, './');
        }

        return null;
    }

    private function nodeText(DOMXPath $xpath, string $query): string
    {
        return $this->cleanText($xpath->query($query)?->item(0)?->textContent ?? '');
    }

    private function cleanText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            throw new RuntimeException('Não foi possível interpretar o HTML da Nerdin.');
        }

        return new DOMXPath($document);
    }
}
