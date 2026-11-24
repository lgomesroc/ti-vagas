<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class NortJobsSource implements VacancySource
{
    private array $stats = [];

    public function __construct(
        private readonly string $sourceName,
        private readonly string $catalogUrl,
        private readonly int $maxPages = 3,
        private readonly int $maxJobs = 120,
        private readonly int $requestDelayMs = 150,
        private readonly ?string $caBundle = null,
    ) {
    }

    public function name(): string
    {
        return $this->sourceName;
    }

    public function diagnostics(): array
    {
        return $this->stats;
    }

    public function fetch(): iterable
    {
        $this->stats = [
            'Páginas consultadas' => 0,
            'Cartões encontrados' => 0,
            'Detalhes consultados' => 0,
            'Vagas de TI júnior enviadas' => 0,
            'Descartadas por senioridade/área' => 0,
            'Falhas' => 0,
        ];

        $pages = $this->catalogPages();
        $jobs = [];
        $seen = [];

        foreach ($pages as $pageUrl) {
            try {
                $html = $this->get($pageUrl);
            } catch (Throwable $exception) {
                $this->stats['Falhas']++;
                Log::warning('NortJobs: falha ao consultar catálogo.', [
                    'source' => $this->sourceName,
                    'url' => $pageUrl,
                    'error' => $exception->getMessage(),
                ]);
                continue;
            }

            $this->stats['Páginas consultadas']++;

            foreach ($this->parseCards($html) as $card) {
                $this->stats['Cartões encontrados']++;

                if (isset($seen[$card['external_id']])) {
                    continue;
                }
                $seen[$card['external_id']] = true;

                if (count($jobs) >= $this->maxJobs) {
                    break 2;
                }

                try {
                    $detailHtml = $this->get($card['detail_url']);
                    $this->stats['Detalhes consultados']++;
                } catch (Throwable $exception) {
                    $this->stats['Falhas']++;
                    Log::warning('NortJobs: falha ao consultar detalhe da vaga.', [
                        'source' => $this->sourceName,
                        'external_id' => $card['external_id'],
                        'url' => $card['detail_url'],
                        'error' => $exception->getMessage(),
                    ]);
                    $this->delay();
                    continue;
                }

                $detail = $this->parseDetail($detailHtml, $card);
                if (!$this->isEligible($detail, $card)) {
                    $this->stats['Descartadas por senioridade/área']++;
                    $this->delay();
                    continue;
                }

                $jobs[$card['external_id']] = [
                    'title' => $card['title'],
                    'company' => $card['company'],
                    'description' => $detail['description'],
                    'location' => $card['location'],
                    'work_model' => $card['work_model'],
                    'employment_type' => $detail['employment_type'],
                    'salary' => $detail['salary'],
                    // A página de detalhe da Nort conduz ao destino original
                    // pelo botão de candidatura quando o destino não é exposto
                    // como href no HTML inicial.
                    'application_url' => $detail['application_url'] ?? $card['detail_url'],
                    'external_id' => $card['external_id'],
                    'original_published_at' => $card['published_at'],
                ];

                $this->stats['Vagas de TI júnior enviadas']++;
                $this->delay();
            }

            $this->delay();
        }

        return array_values($jobs);
    }

    private function catalogPages(): array
    {
        try {
            $html = $this->get($this->catalogUrl);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                "Não foi possível abrir o catálogo {$this->sourceName}: {$exception->getMessage()}",
                previous: $exception
            );
        }

        $urls = [$this->catalogUrl];

        // Segue somente links de paginação publicados pelo próprio catálogo.
        if (preg_match_all('~<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>~is', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $label = trim(strip_tags($match[2]));
                if (!preg_match('/^(próxima|next|›|»|\d+)$/iu', $label)) {
                    continue;
                }

                $url = $this->absoluteUrl(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $this->catalogUrl);
                if ($url !== null && !in_array($url, $urls, true)) {
                    $urls[] = $url;
                }

                if (count($urls) >= max(1, $this->maxPages)) {
                    break;
                }
            }
        }

        return array_slice($urls, 0, max(1, $this->maxPages));
    }

    private function parseCards(string $html): array
    {
        // O catálogo Next.js serializa os cartões em self.__next_f.
        // Decodifica os segmentos Flight antes de extrair os atributos.
        $payload = $html;
        if (preg_match_all('~self\.__next_f\.push\(\[1,"((?:\\\\.|[^"\\\\])*)"\]\)~s', $html, $segments)) {
            $decoded = '';
            foreach ($segments[1] as $segment) {
                $value = json_decode('"' . $segment . '"');
                if (is_string($value)) {
                    $decoded .= $value;
                }
            }
            if ($decoded !== '') {
                $payload = $decoded;
            }
        }

        $cards = [];
        if (!preg_match_all('~"data-job-company":true,"children":"((?:\\\\.|[^"\\\\])*)".{0,5000}?"data-catalog-job":"([^"]+)".{0,1500}?"href":"([^"]+)".{0,500}?"children":\["((?:\\\\.|[^"\\\\])*)"~s', $payload, $matches, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($matches as $match) {
            $company = $this->decodeString($match[1]);
            $externalId = trim($match[2]);
            $detailPath = $this->decodeString($match[3]);
            $title = $this->decodeString($match[4]);

            if ($company === '' || $externalId === '' || $title === '') {
                continue;
            }

            $cardStart = max(0, strpos($payload, '"data-catalog-job":"' . $externalId . '"') - 1500);
            $cardChunk = substr($payload, $cardStart, 6000);

            $publishedAt = null;
            if (preg_match('~"data-job-date":true,"dateTime":"([^"]+)"~', $cardChunk, $dateMatch)) {
                try {
                    $publishedAt = CarbonImmutable::parse($dateMatch[1])->toIso8601String();
                } catch (Throwable) {
                    $publishedAt = null;
                }
            }

            if ($publishedAt === null) {
                continue;
            }

            $location = null;
            if (preg_match('~"data-job-location":true.{0,800}?"children":"((?:\\\\.|[^"\\\\])*)"~s', $cardChunk, $locationMatch)) {
                $location = $this->decodeString($locationMatch[1]);
            }

            $workModel = null;
            if (preg_match('~"data-mode":"(remote|hybrid|onsite)"~', $cardChunk, $modeMatch)) {
                $workModel = match ($modeMatch[1]) {
                    'remote' => 'Remoto',
                    'hybrid' => 'Híbrido',
                    'onsite' => 'Presencial',
                };
            }

            $detailUrl = $this->absoluteUrl($detailPath, $this->catalogUrl);
            if ($detailUrl === null) {
                continue;
            }

            $cards[] = [
                'external_id' => $externalId,
                'company' => $company,
                'title' => $title,
                'published_at' => $publishedAt,
                'location' => $location,
                'work_model' => $workModel,
                'detail_url' => $detailUrl,
            ];
        }

        return $cards;
    }

    private function parseDetail(string $html, array $card): array
    {
        $description = '';
        if (preg_match('~<meta\s+name=["\']description["\']\s+content=["\']([^"\']+)["\']~i', $html, $match)) {
            $description = $this->cleanText(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        // Quando o destino externo aparece como link no HTML, prioriza-o.
        $applicationUrl = null;
        if (preg_match_all('~<a\b([^>]*href=["\']([^"\']+)["\'][^>]*)>~i', $html, $links, PREG_SET_ORDER)) {
            foreach ($links as $link) {
                $attributes = $link[1];
                $href = html_entity_decode($link[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (
                    preg_match('/data-catalog-cta=["\'](?:apply|continue_to_job)["\']/i', $attributes)
                    || preg_match('/aria-label=["\'][^"\']*(?:candidatar|acessar vaga)[^"\']*["\']/i', $attributes)
                ) {
                    $applicationUrl = $this->absoluteUrl($href, $card['detail_url']);
                    if ($applicationUrl !== null) {
                        break;
                    }
                }
            }
        }

        // Algumas versões da página publicam a descrição em metadados JSON.
        if (mb_strlen($description) < 80 && preg_match('~"description":"((?:\\\\.|[^"\\\\]){80,})"~s', $html, $match)) {
            $candidate = $this->decodeString($match[1]);
            if (mb_strlen($candidate) > mb_strlen($description)) {
                $description = $candidate;
            }
        }

        $employmentType = null;
        if (preg_match('~"employmentTypes":\["([^"]+)"~', $html, $match)) {
            $employmentType = match (strtoupper($match[1])) {
                'FULL_TIME' => 'Tempo integral',
                'PART_TIME' => 'Meio período',
                'CONTRACTOR' => 'Contrato',
                'TEMPORARY' => 'Temporário',
                default => null,
            };
        }

        $salary = null;
        if (preg_match('~"salary":\{"[^"]*from":([^,}]+)~', $html, $match) && is_numeric($match[1])) {
            $salary = $match[1];
        }

        // Next.js mantém os metadados dentro de strings JSON escapadas no HTML.
        $structured = str_replace('\\"', '"', $html);
        $isTech = preg_match('~"isTech":true~', $structured) === 1;
        $seniority = null;
        if (preg_match('~"attributes":\{.{0,1200}?"seniority":"([^"]+)"~s', $structured, $seniorityMatch)) {
            $seniority = strtolower($seniorityMatch[1]);
        } elseif (preg_match('~"seniority":"(entry|junior|intern|mid|senior|lead|executive)"~', $structured, $seniorityMatch)) {
            $seniority = strtolower($seniorityMatch[1]);
        }

        return [
            'description' => $description !== '' ? $description : 'Consulte os detalhes da oportunidade e os requisitos diretamente na página da Nort.',
            'application_url' => $applicationUrl,
            'employment_type' => $employmentType,
            'salary' => $salary,
            'is_tech' => $isTech,
            'seniority' => $seniority,
        ];
    }

    private function isEligible(array $detail, array $card): bool
    {
        // Exige classificação explícita de tecnologia e nível inicial/júnior.
        // "intern" (estágio), pleno e níveis superiores são sempre descartados.
        if (($detail['is_tech'] ?? false) !== true) {
            return false;
        }

        if (!in_array($detail['seniority'] ?? null, ['entry', 'junior'], true)) {
            return false;
        }

        $text = $card['title'] . ' ' . $detail['description'];
        if (preg_match('/\b(est[aá]gio|intern(ship)?|pleno|mid[- ]level|s[eê]nior|senior|lead|staff|principal|especialista)\b/iu', $text)) {
            return false;
        }

        return true;
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
        if (trim($response->body()) === '') {
            throw new RuntimeException('A Nort retornou uma página vazia.');
        }

        return $response->body();
    }

    private function absoluteUrl(string $href, string $baseUrl): ?string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, 'javascript:') || str_starts_with($href, '#')) {
            return null;
        }
        if (str_starts_with($href, '//')) {
            return 'https:' . $href;
        }
        if (str_starts_with($href, '/')) {
            return 'https://nortjobs.com' . $href;
        }
        if (filter_var($href, FILTER_VALIDATE_URL)) {
            return $href;
        }

        return null;
    }

    private function decodeString(string $value): string
    {
        $decoded = json_decode('"' . $value . '"');
        return is_string($decoded) ? $this->cleanText($decoded) : $this->cleanText($value);
    }

    private function cleanText(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '');
    }

    private function delay(): void
    {
        if ($this->requestDelayMs > 0) {
            usleep($this->requestDelayMs * 1000);
        }
    }
}
