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

class RioVagasSource implements VacancySource
{
    public function __construct(
        private readonly string $baseUrl = 'https://riovagas.com.br',
        private readonly ?string $caBundle = null,
        private readonly int $maxPages = 3,
        private readonly int $maxJobs = 80,
        private readonly int $initialLookbackDays = 60,
        private readonly ?VacancyLocationFilter $locationFilter = null,
    ) {
    }

    public function name(): string
    {
        return 'riovagas';
    }

    public function fetch(): iterable
    {
        $cutoff = CarbonImmutable::now('America/Sao_Paulo')->subDays(max(1, $this->initialLookbackDays));
        $jobs = [];
        $seen = [];

        for ($page = 1; $page <= max(1, $this->maxPages) && count($jobs) < $this->maxJobs; $page++) {
            $url = rtrim($this->baseUrl, '/') . '/riovagas/';
            if ($page > 1) {
                $url .= 'page/' . $page . '/';
            }

            try {
                $html = $this->get($url, 'text/html,application/xhtml+xml');
                $xpath = $this->xpath($html);
            } catch (\Throwable $exception) {
                Log::warning('RioVagas: falha ao consultar página de vagas.', [
                    'url' => $url,
                    'message' => $exception->getMessage(),
                ]);
                break;
            }

            $links = $xpath->query('//article//h2//a[@href] | //article//h3//a[@href] | //h2//a[@href] | //h3//a[@href]');
            if ($links === false || $links->length === 0) {
                break;
            }

            $found = 0;
            foreach ($links as $link) {
                if (!$link instanceof DOMElement) {
                    continue;
                }

                $articleUrl = $this->absoluteUrl($link->getAttribute('href'));
                if ($articleUrl === null || isset($seen[$articleUrl])) {
                    continue;
                }
                $seen[$articleUrl] = true;
                $found++;

                try {
                    $job = $this->parseArticle(
                        $this->get($articleUrl, 'text/html,application/xhtml+xml'),
                        $articleUrl,
                        $cutoff
                    );
                    if ($job !== null) {
                        $filter = $this->locationFilter ?? new VacancyLocationFilter();
                        if ($filter->isEligibleForCollection(
                            $job['location'],
                            $job['title'],
                            $job['description'],
                            $job['work_model']
                        )) {
                            $jobs[$job['external_id']] = $job;
                        }
                    }
                } catch (\Throwable $exception) {
                    Log::warning('RioVagas: falha ao interpretar anúncio.', [
                        'url' => $articleUrl,
                        'message' => $exception->getMessage(),
                    ]);
                }

                if (count($jobs) >= $this->maxJobs) {
                    break;
                }
            }

            if ($found === 0) {
                break;
            }
        }

        return array_values($jobs);
    }

    private function parseArticle(string $html, string $url, CarbonImmutable $cutoff): ?array
    {
        $xpath = $this->xpath($html);
        $title = $this->nodeText($xpath, '//article//h1 | //main//h1 | //h1');
        $bodyNode = $xpath->query('//article')?->item(0)
            ?? $xpath->query('//main')?->item(0)
            ?? $xpath->query('//body')?->item(0);
        $description = $this->cleanText($bodyNode?->textContent ?? '');

        if ($title === '' || !$this->isTechnologyVacancy($title, $description)) {
            return null;
        }

        $publishedAt = $this->publishedAt($xpath);
        if ($publishedAt !== null && $publishedAt->lt($cutoff)) {
            return null;
        }

        $company = $this->field($description, 'Empresa', ['Cargo', 'Regime de Contratação', 'Número de Vagas']);
        if ($company === '') {
            $company = 'Não informada';
        }

        $cargo = $this->field($description, 'Cargo', ['Regime de Contratação', 'Número de Vagas']);
        if ($cargo !== '') {
            $title = $cargo;
        }

        $city = $this->field($description, 'Cidade', ['Benefícios', 'Descrição do Cargo', 'Forma de Trabalho']);
        $neighborhood = $this->field($description, 'Bairro', ['Cidade', 'Benefícios', 'Descrição do Cargo']);
        $location = trim(implode(', ', array_filter([$neighborhood, $city, 'RJ'])));
        if ($location === '') {
            $location = 'Rio de Janeiro, RJ';
        }

        $workModel = null;
        if (preg_match('/Forma de Trabalho:\s*(Remoto|Home Office|H[ií]brido|Presencial)/iu', $description, $match)) {
            $workModel = Str::lower($match[1]);
            $workModel = str_contains($workModel, 'remoto') || str_contains($workModel, 'home')
                ? 'Remoto'
                : (str_contains($workModel, 'h') ? 'Híbrido' : 'Presencial');
        }

        $employmentType = $this->field($description, 'Regime de Contratação', [
            'Número de Vagas', 'Descrição do Cargo', 'Forma de Trabalho'
        ]);
        $salary = $this->field($description, 'Salário', [
            'Informações Adicionais', 'Benefícios', 'Horário de Expediente'
        ]);

        return [
            'title' => $title,
            'company' => $company,
            'description' => $description,
            'location' => $location,
            'work_model' => $workModel,
            'employment_type' => $employmentType !== '' ? $employmentType : null,
            'salary' => $salary !== '' ? $salary : null,
            'application_url' => $url,
            'external_id' => 'riovagas:' . Str::slug(basename(rtrim((string) parse_url($url, PHP_URL_PATH), '/'))),
            'original_published_at' => $publishedAt?->toIso8601String(),
        ];
    }

    private function isTechnologyVacancy(string $title, string $description): bool
    {
        return (bool) preg_match(
            '/\b(desenvolvedor|desenvolvedora|programador|programadora|software|sistemas|php|laravel|\.net|c#|java|python|javascript|typescript|backend|back-end|frontend|front-end|full.?stack|suporte t[eé]cnico|analista de suporte|infraestrutura de ti|redes de computadores|devops|cloud|qa|quality assurance|teste de software|seguran[cç]a da informa[cç]ão|banco de dados|dados|noc|soc)\b/iu',
            $title . ' ' . $description
        );
    }

    private function field(string $text, string $label, array $stops): string
    {
        $stopPattern = implode('|', array_map(static fn ($value) => preg_quote($value, '/'), $stops));
        $pattern = '/\b' . preg_quote($label, '/') . '\s*:\s*(.+?)(?=\s+(?:' . $stopPattern . ')\s*:|$)/iu';
        return preg_match($pattern, $text, $match)
            ? trim($match[1], " \t\n\r\0\x0B:-")
            : '';
    }

    private function publishedAt(DOMXPath $xpath): ?CarbonImmutable
    {
        $value = $xpath->query('//time[@datetime]')?->item(0)?->attributes?->getNamedItem('datetime')?->nodeValue
            ?? $xpath->query('//meta[@property="article:published_time"]')?->item(0)?->getAttribute('content');

        if (!$value) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'America/Sao_Paulo');
        } catch (\Throwable) {
            return null;
        }
    }

    private function get(string $url, string $accept): string
    {
        $response = Http::timeout(35)
            ->withOptions(['verify' => $this->caBundle ?: true])
            ->withUserAgent('Mozilla/5.0 (compatible; TI-Vagas/1.0; +https://github.com/lgomesroc/ti-vagas)')
            ->accept($accept)
            ->get($url);
        $response->throw();

        if (trim($response->body()) === '') {
            throw new RuntimeException('RioVagas retornou conteúdo vazio.');
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
}
