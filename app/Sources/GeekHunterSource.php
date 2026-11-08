<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GeekHunterSource implements VacancySource
{
    private const SEARCH_URL =
        'https://www.geekhunter.com/pt/vagas?experienceLevel=entry&workModality=remote%2Cremote-in-city';

    private const ALLOWED_AREAS = [
        'desenvolvedor',
        'desenvolvedora',
        'developer',
        'software engineer',
        'engenheiro de software',
        'programador',
        'programadora',
        'backend',
        'back-end',
        'frontend',
        'front-end',
        'full stack',
        'fullstack',
        'php',
        'laravel',
        'java',
        'dotnet',
        '.net',
        'c#',
        'python',
        'javascript',
        'typescript',
        'angular',
        'react',
        'node',
        'devops',
        'infraestrutura',
        'infrastructure',
        'cloud',
        'suporte tecnico',
        'analista de suporte',
        'service desk',
        'help desk',
        'redes',
        'network',
        'seguranca da informacao',
        'cybersecurity',
        'qa',
        'quality assurance',
        'testes de software',
        'tester',
        'banco de dados',
        'database',
        'dba',
        'analista de sistemas',
        'sistemas',
        'sap abap',
    ];

    public function __construct(
        private readonly ?string $caBundle = null,
        private readonly int $maxJobs = 50,
    ) {
    }

    public function name(): string
    {
        return 'geekhunter';
    }

    public function fetch(): iterable
    {
        $response = $this->http()->get(self::SEARCH_URL);
        $response->throw();

        $urls = $this->extractJobUrls($response->body());
        $vacancies = [];

        foreach (array_slice($urls, 0, $this->maxJobs) as $url) {
            try {
                $vacancy = $this->fetchJob($url);

                if ($vacancy !== null) {
                    $vacancies[] = $vacancy;
                }
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return $vacancies;
    }

    private function http()
    {
        return Http::accept('text/html')
            ->timeout(30)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; TI-Vagas/1.0)',
            ])
            ->withOptions([
                'verify' => $this->caBundle ?: true,
            ]);
    }

    private function extractJobUrls(string $html): array
    {
        preg_match_all(
            '~href=["\'](https://www\.geekhunter\.com/pt/[^"\']+/jobs/[^"\']+)["\']~i',
            $html,
            $matches
        );

        $urls = array_map(
            static fn (string $url): string => html_entity_decode(
                $url,
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            ),
            $matches[1] ?? []
        );

        return array_values(array_unique($urls));
    }

    private function fetchJob(string $url): ?array
    {
        $response = $this->http()->get($url);
        $response->throw();

        $html = $response->body();
        $posting = $this->extractJobPosting($html);
        $pageText = $this->extractPageText($html);

        $title = trim(
            $posting['title'] ?? $this->extractTitle($html)
        );

        if ($title === '' || !$this->isJunior($title)) {
            return null;
        }

        if (!$this->isTechnologyArea($title)) {
            return null;
        }

        $description = $this->cleanText(
            $posting['description'] ?? ''
        );

        if ($description === '') {
            return null;
        }

        if (!$this->isTechnologyArea($title . ' ' . $description)) {
            return null;
        }

        $publishedAt = $this->normalizePublicationDate(
            $posting['datePosted'] ?? null,
            $pageText
        );

        if ($publishedAt === null) {
            return null;
        }

        $company = $posting['hiringOrganization']['name'] ?? null;

        if (!is_string($company) || trim($company) === '') {
            $company = $this->companyFromUrl($url);
        }

        return [
            'title' => $title,
            'company' => trim($company),
            'description' => $description,
            'location' => $this->extractLocation($posting)
                ?? 'Brasil (remoto)',
            'work_model' => 'Remoto',
            'employment_type' => $this->normalizeEmploymentType(
                $posting['employmentType'] ?? null,
                $pageText
            ),
            'salary' => $this->extractSalary($posting),
            'application_url' => $url,
            'external_id' => $this->externalId($url),
            'original_published_at' => $publishedAt,
        ];
    }

    private function isJunior(string $title): bool
    {
        $title = Str::ascii(mb_strtolower($title));

        if (preg_match(
            '/\b(estagio|estagiario|intern|trainee|pleno|senior|sr\.?|staff|principal|coordenador|gerente|manager|director|diretor)\b/',
            $title
        )) {
            return false;
        }

        return preg_match(
            '/\b(junior|jr\.?|entry[\s-]?level|nivel inicial)\b/',
            $title
        ) === 1;
    }

    private function isTechnologyArea(string $text): bool
    {
        $normalized = Str::ascii(mb_strtolower($text));

        foreach (self::ALLOWED_AREAS as $area) {
            if (str_contains($normalized, Str::ascii($area))) {
                return true;
            }
        }

        return false;
    }

    private function extractJobPosting(string $html): array
    {
        preg_match_all(
            '~<script\b[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~is',
            $html,
            $matches
        );

        foreach ($matches[1] ?? [] as $json) {
            $data = json_decode(
                html_entity_decode($json, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                true
            );

            if (!is_array($data)) {
                continue;
            }

            $posting = $this->findJobPosting($data);

            if ($posting !== null) {
                return $posting;
            }
        }

        return [];
    }

    private function findJobPosting(array $data): ?array
    {
        $type = $data['@type'] ?? null;

        if (
            $type === 'JobPosting'
            || (is_array($type) && in_array('JobPosting', $type, true))
        ) {
            return $data;
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $found = $this->findJobPosting($value);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function extractTitle(string $html): string
    {
        if (preg_match('~<h1\b[^>]*>(.*?)</h1>~is', $html, $match)) {
            return $this->cleanText($match[1]);
        }

        return '';
    }

    private function extractPageText(string $html): string
    {
        $html = preg_replace(
            '~<(script|style|noscript|svg|nav|header|footer)\b[^>]*>.*?</\1>~is',
            ' ',
            $html
        );

        return $this->cleanText(strip_tags($html));
    }

    private function cleanText(string $content): string
    {
        $content = html_entity_decode(
            $content,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $content = strip_tags($content);
        $content = preg_replace('/[\t\r ]+/', ' ', $content);
        $content = preg_replace('/\n\s*\n+/', "\n\n", $content);

        return trim($content);
    }

    private function normalizePublicationDate(
        mixed $date,
        string $pageText
    ): ?string {
        try {
            if (is_string($date) && trim($date) !== '') {
                return CarbonImmutable::parse($date)
                    ->utc()
                    ->toIso8601String();
            }

            if (preg_match(
                '/publicad[ao]\s+há\s+(\d+)\s+(dia|dias|semana|semanas|m[eê]s|meses|ano|anos)/iu',
                $pageText,
                $match
            )) {
                $amount = (int) $match[1];
                $unit = mb_strtolower($match[2]);
                $published = CarbonImmutable::now();

                if (str_starts_with($unit, 'dia')) {
                    $published = $published->subDays($amount);
                } elseif (str_starts_with($unit, 'semana')) {
                    $published = $published->subWeeks($amount);
                } elseif (str_starts_with($unit, 'm')) {
                    $published = $published->subMonths($amount);
                } else {
                    $published = $published->subYears($amount);
                }

                return $published->utc()->toIso8601String();
            }

            if (preg_match('/publicad[ao]\s+hoje/iu', $pageText)) {
                return CarbonImmutable::now()->utc()->toIso8601String();
            }

            if (preg_match('/publicad[ao]\s+ontem/iu', $pageText)) {
                return CarbonImmutable::yesterday()->utc()->toIso8601String();
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function normalizeEmploymentType(
        mixed $type,
        string $pageText
    ): ?string {
        if (preg_match('/\b(CLT|PJ)\b/i', $pageText, $match)) {
            return strtoupper($match[1]);
        }

        if (is_array($type)) {
            $type = implode(', ', $type);
        }

        if (!is_string($type) || trim($type) === '') {
            return null;
        }

        $normalized = Str::ascii(mb_strtolower($type));

        return match ($normalized) {
            'full_time', 'full-time' => 'Tempo integral',
            'part_time', 'part-time' => 'Meio período',
            'contractor', 'contract' => 'Contrato',
            'temporary' => 'Temporário',
            default => trim($type),
        };
    }

    private function extractLocation(array $posting): ?string
    {
        $locations = $posting['jobLocation'] ?? [];

        if (isset($locations['address'])) {
            $locations = [$locations];
        }

        foreach ($locations as $location) {
            $address = $location['address'] ?? [];

            $parts = array_filter([
                $address['addressLocality'] ?? null,
                $address['addressRegion'] ?? null,
                $address['addressCountry'] ?? null,
            ]);

            if ($parts !== []) {
                return implode(', ', $parts) . ' (remoto)';
            }
        }

        return null;
    }

    private function extractSalary(array $posting): ?string
    {
        $salary = $posting['baseSalary'] ?? null;

        if (!is_array($salary)) {
            return null;
        }

        $value = $salary['value'] ?? [];
        $min = $value['minValue'] ?? null;
        $max = $value['maxValue'] ?? null;
        $currency = $salary['currency'] ?? 'BRL';

        if ($min !== null && $max !== null) {
            return "{$currency} {$min} - {$max}";
        }

        if ($min !== null) {
            return "{$currency} {$min}";
        }

        if ($max !== null) {
            return "{$currency} {$max}";
        }

        return null;
    }

    private function companyFromUrl(string $url): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $parts = explode('/', $path);
        $slug = $parts[1] ?? 'GeekHunter';

        return Str::title(str_replace('-', ' ', $slug));
    }

    private function externalId(string $url): string
    {
        return basename((string) parse_url($url, PHP_URL_PATH));
    }
}
