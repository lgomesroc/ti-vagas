<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Services\VacancyLocationFilter;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class CompleoSource implements VacancySource
{
    private const SITEMAP_URL = 'https://jobs.compleo.app/sitemap.xml';

    private readonly VacancyLocationFilter $locationFilter;

    public function __construct(
        private readonly string $companyKey,
        private readonly string $companyName,
        private readonly ?string $caBundle = null,
        private readonly int $maxJobs = 200,
        ?VacancyLocationFilter $locationFilter = null,
    ) {
        $this->locationFilter = $locationFilter ?? new VacancyLocationFilter();
    }

    public function name(): string
    {
        return 'compleo:' . $this->companyKey;
    }

    public function fetch(): iterable
    {
        $client = Http::timeout(45)
            ->withOptions(['verify' => $this->caBundle ?: true])
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; TI-Vagas/1.0)',
                'Accept' => 'application/xml,text/html,application/xhtml+xml',
            ]);

        $response = $client->get(self::SITEMAP_URL);
        $response->throw();

        $urls = $this->extractJobUrls($response->body());

        if ($urls === []) {
            throw new RuntimeException(
                'O sitemap público do Compleo não retornou URLs de vagas.'
            );
        }

        $vacancies = [];
        $failures = 0;

        foreach (array_slice($urls, 0, $this->maxJobs) as $url) {
            try {
                $detail = $client->get($url);
                $detail->throw();

                $vacancy = $this->parseJobPosting($detail->body(), $url);

                if ($vacancy !== null) {
                    $vacancies[] = $vacancy;
                }
            } catch (\Throwable $exception) {
                $failures++;

                Log::warning('Falha ao consultar vaga do Compleo.', [
                    'company' => $this->companyName,
                    'url' => $url,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if ($failures > 0 && $vacancies === []) {
            throw new RuntimeException(
                "Compleo/{$this->companyKey}: falha ao consultar {$failures} página(s) de vagas."
            );
        }

        return $vacancies;
    }

    private function extractJobUrls(string $xml): array
    {
        $previousState = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument();

            if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR)) {
                throw new RuntimeException(
                    'Não foi possível interpretar o sitemap do Compleo.'
                );
            }

            $xpath = new DOMXPath($document);
            $nodes = $xpath->query('//*[local-name()="loc"]');

            if ($nodes === false) {
                throw new RuntimeException(
                    'Não foi possível localizar URLs no sitemap do Compleo.'
                );
            }

            $prefix = 'https://jobs.compleo.app/'
                . rawurlencode($this->companyKey)
                . '/jobdetail/';

            $urls = [];

            foreach ($nodes as $node) {
                $url = trim($node->textContent);

                if (!str_starts_with($url, $prefix)) {
                    continue;
                }

                $path = parse_url($url, PHP_URL_PATH) ?: '';

                if (
                    preg_match(
                        '~^/' . preg_quote($this->companyKey, '~')
                        . '/jobdetail/[A-Za-z0-9]+/?$~',
                        $path
                    )
                ) {
                    $urls[] = $url;
                }
            }

            return array_values(array_unique($urls));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousState);
        }
    }

    private function parseJobPosting(string $html, string $url): ?array
    {
        if (trim($html) === '') {
            return null;
        }

        preg_match_all(
            '~<script\b[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~is',
            $html,
            $matches
        );

        $posting = null;

        foreach ($matches[1] ?? [] as $json) {
            $decoded = json_decode(
                html_entity_decode($json, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                true
            );

            if (is_array($decoded)) {
                $posting = $this->findJobPosting($decoded);

                if ($posting !== null) {
                    break;
                }
            }
        }

        if ($posting === null) {
            return null;
        }

        $title = $this->cleanText((string) ($posting['title'] ?? ''));

        $description = $this->cleanDescription(
            (string) ($posting['description'] ?? '')
        );

        $publishedAt = $this->normalizeDate(
            $posting['datePosted'] ?? null
        );

        if ($title === '' || $description === '' || $publishedAt === null) {
            return null;
        }

        $locationData = $this->extractLocation($posting);
        $location = $locationData['label'];

        $workModel = $this->locationFilter->classifyWorkModel(
            $title,
            $location,
            $description
        );

        if (
            !$this->locationFilter->isEligibleForCollection(
                $location,
                $title,
                $description,
                $workModel
            )
        ) {
            return null;
        }

        $jobId = basename(
            rtrim((string) parse_url($url, PHP_URL_PATH), '/')
        );

        return [
            'title' => $title,
            'company' => $this->companyName,
            'description' => $description,
            'location' => $location !== '' ? $location : null,
            'work_model' => $workModel,
            'employment_type' => $this->normalizeEmploymentType(
                $posting['employmentType'] ?? null
            ),
            'salary' => $this->extractSalary(
                $posting['baseSalary'] ?? null
            ),
            'application_url' => $url,
            'external_id' => $this->name() . ':' . $jobId,
            'original_published_at' => $publishedAt,
            'city' => $locationData['city'],
            'state' => $locationData['state'],
            'state_code' => null,
            'country' => $locationData['country'],
            'country_code' => $locationData['country_code'],
            'latitude' => null,
            'longitude' => null,
        ];
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

    private function extractLocation(array $posting): array
    {
        $result = [
            'label' => '',
            'city' => null,
            'state' => null,
            'country' => null,
            'country_code' => null,
        ];

        $locations = $posting['jobLocation'] ?? [];

        if (!is_array($locations)) {
            return $result;
        }

        if (isset($locations['address'])) {
            $locations = [$locations];
        }

        foreach ($locations as $place) {
            if (!is_array($place)) {
                continue;
            }

            $address = $place['address'] ?? [];

            if (!is_array($address)) {
                continue;
            }

            $city = $this->scalarText(
                $address['addressLocality'] ?? null
            );

            $state = $this->scalarText(
                $address['addressRegion'] ?? null
            );

            $country = $this->scalarText(
                $address['addressCountry'] ?? null
            );

            $parts = array_filter([$city, $state, $country]);

            if ($parts !== []) {
                return [
                    'label' => implode(', ', $parts),
                    'city' => $city,
                    'state' => $state,
                    'country' => $country,
                    'country_code' => $country !== null
                        && in_array(strtoupper($country), ['BR', 'BRA'], true)
                            ? 'BR'
                            : null,
                ];
            }
        }

        if (($posting['jobLocationType'] ?? null) === 'TELECOMMUTE') {
            $result['label'] = 'Brasil (remoto)';
            $result['country'] = 'Brasil';
            $result['country_code'] = 'BR';
        }

        return $result;
    }

    private function extractSalary(mixed $salary): ?string
    {
        if (!is_array($salary)) {
            return null;
        }

        $value = $salary['value'] ?? [];

        if (!is_array($value)) {
            return null;
        }

        $min = $value['minValue'] ?? null;
        $max = $value['maxValue'] ?? null;

        $currency = $this->scalarText(
            $salary['currency'] ?? null
        ) ?: 'BRL';

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

    private function normalizeEmploymentType(mixed $type): ?string
    {
        if (is_array($type)) {
            $type = implode(', ', array_map('strval', $type));
        }

        if (!is_string($type) || trim($type) === '') {
            return null;
        }

        return match (Str::ascii(mb_strtolower(trim($type)))) {
            'full_time', 'full-time' => 'Tempo integral',
            'part_time', 'part-time' => 'Meio período',
            'contractor', 'contract' => 'Contrato',
            'temporary' => 'Temporário',
            default => $this->cleanText($type),
        };
    }

    private function normalizeDate(mixed $date): ?string
    {
        if (!is_string($date) || trim($date) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($date, 'UTC')
                ->utc()
                ->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function scalarText(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['name'] ?? $value['value'] ?? null;
        }

        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $value = $this->cleanText((string) $value);

        return $value !== '' ? $value : null;
    }

    private function cleanDescription(string $description): string
    {
        $description = preg_replace(
            '~<(script|style)\b[^>]*>.*?</\1>~is',
            ' ',
            $description
        );

        $description = preg_replace(
            '~</(p|div|li|br|h[1-6])\s*>~i',
            "\n",
            $description
        );

        return $this->cleanText(strip_tags($description));
    }

    private function cleanText(string $text): string
    {
        $text = html_entity_decode(
            $text,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }
}
