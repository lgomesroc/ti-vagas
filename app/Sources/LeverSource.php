<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Services\VacancyLocationFilter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class LeverSource implements VacancySource
{
    private const PAGE_SIZE = 100;

    private readonly VacancyLocationFilter $locationFilter;

    public function __construct(
        private readonly string $site,
        private readonly string $companyName,
        private readonly ?string $caBundle = null,
        private readonly string $region = 'global',
        private readonly int $maxJobs = 200,
        ?VacancyLocationFilter $locationFilter = null,
    ) {
        $this->locationFilter = $locationFilter
            ?? new VacancyLocationFilter();
    }

    public function name(): string
    {
        return 'lever-' . $this->site;
    }

    public function fetch(): iterable
    {
        $apiHost = $this->region === 'eu'
            ? 'api.eu.lever.co'
            : 'api.lever.co';

        $endpoint = sprintf(
            'https://%s/v0/postings/%s',
            $apiHost,
            rawurlencode($this->site)
        );

        $vacancies = [];
        $skip = 0;
        $processed = 0;

        while ($processed < $this->maxJobs) {
            $limit = min(
                self::PAGE_SIZE,
                $this->maxJobs - $processed
            );

            $response = Http::acceptJson()
                ->timeout(60)
                ->withHeaders([
                    'User-Agent' => 'TI-Vagas/1.0',
                ])
                ->withOptions([
                    'verify' => $this->caBundle ?: true,
                ])
                ->get($endpoint, [
                    'mode' => 'json',
                    'skip' => $skip,
                    'limit' => $limit,
                ]);

            $response->throw();

            $postings = $response->json();

            if (!is_array($postings)) {
                throw new RuntimeException(
                    'Resposta inválida recebida da API da Lever: ' .
                    $this->site
                );
            }

            if ($postings === []) {
                break;
            }

            foreach ($postings as $posting) {
                if ($processed >= $this->maxJobs) {
                    break;
                }

                $processed++;

                if (!is_array($posting)) {
                    continue;
                }

                $vacancy = $this->normalizePosting($posting);

                if ($vacancy !== null) {
                    $vacancies[] = $vacancy;
                }
            }

            $skip += count($postings);

            if (count($postings) < $limit) {
                break;
            }
        }

        return $vacancies;
    }

    private function normalizePosting(array $posting): ?array
    {
        $id = trim($posting['id'] ?? '');
        $title = trim($posting['text'] ?? '');

        if ($id === '' || $title === '') {
            return null;
        }

        $categories = $posting['categories'] ?? [];

        if (!is_array($categories)) {
            $categories = [];
        }

        $country = strtoupper(trim($posting['country'] ?? ''));

        if ($country !== '' && $country !== 'BR') {
            return null;
        }

        $location = $this->extractLocation($categories);

        if ($country === 'BR' && !str_contains(
            mb_strtolower($location),
            'brasil'
        )) {
            $location = trim($location . ', Brasil', ', ');
        }

        $description = $this->extractDescription($posting);

        $workModel = $this->normalizeWorkplaceType(
            $posting['workplaceType'] ?? ''
        );

        if ($workModel === null) {
            $workModel = $this->locationFilter->classifyWorkModel(
                $title,
                $location,
                $description
            );
        }

        if (!$this->locationFilter->isEligibleForCollection(
            $location,
            $title,
            $description,
            $workModel
        )) {
            return null;
        }

        $publishedAt = $this->normalizePublicationDate(
            $posting['createdAt'] ?? null
        );

        if ($publishedAt === null) {
            return null;
        }

        $applicationUrl = trim($posting['applyUrl'] ?? '');

        if (!$this->isValidUrl($applicationUrl)) {
            $applicationUrl = trim($posting['hostedUrl'] ?? '');
        }

        if (!$this->isValidUrl($applicationUrl)) {
            return null;
        }

        return [
            'title' => $title,
            'company' => $this->companyName,
            'description' => $description,
            'location' => $location !== '' ? $location : null,
            'city' => null,
            'state' => null,
            'country' => $country !== '' ? $country : null,
            'work_model' => $workModel,
            'employment_type' => $this->normalizeEmploymentType(
                $categories['commitment'] ?? ''
            ),
            'salary' => $this->normalizeSalary($posting),
            'application_url' => $applicationUrl,
            'external_id' => $this->site . ':' . $id,
            'original_published_at' => $publishedAt,
        ];
    }

    private function extractLocation(array $categories): string
    {
        $locations = [];

        $primaryLocation = trim(
            $categories['location'] ?? ''
        );

        if ($primaryLocation !== '') {
            $locations[] = $primaryLocation;
        }

        $allLocations = $categories['allLocations'] ?? [];

        if (is_array($allLocations)) {
            foreach ($allLocations as $location) {
                if (is_string($location) && trim($location) !== '') {
                    $locations[] = trim($location);
                }
            }
        }

        return implode(
            ' / ',
            array_unique($locations)
        );
    }

    private function extractDescription(array $posting): string
    {
        $description = trim(
            $posting['descriptionPlain'] ?? ''
        );

        if ($description === '') {
            $parts = [
                $posting['openingPlain'] ?? '',
                $posting['descriptionBodyPlain'] ?? '',
                $posting['additionalPlain'] ?? '',
            ];

            $description = implode("\n\n", array_filter(
                $parts,
                fn ($part) => is_string($part) && trim($part) !== ''
            ));
        }

        $lists = $posting['lists'] ?? [];

        if (is_array($lists)) {
            foreach ($lists as $list) {
                if (!is_array($list)) {
                    continue;
                }

                $listContent = $list['content'] ?? '';

                if (is_string($listContent) && trim($listContent) !== '') {
                    $description .= "\n\n" .
                        trim($list['text'] ?? 'Informações adicionais') .
                        "\n" .
                        $listContent;
                }
            }
        }

        return $this->cleanDescription($description);
    }

    private function cleanDescription(string $content): string
    {
        $content = html_entity_decode(
            $content,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $content = preg_replace(
            '/<\s*br\s*\/?>|<\/\s*p\s*>|<\/\s*div\s*>|<\/\s*h[1-6]\s*>/i',
            "\n",
            $content
        );

        $content = preg_replace(
            '/<\s*li\b[^>]*>/i',
            "\n- ",
            $content
        );

        $content = strip_tags($content);

        $content = html_entity_decode(
            $content,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $content = preg_replace('/[ \t]+/', ' ', $content);
        $content = preg_replace('/ *\n */', "\n", $content);
        $content = preg_replace('/\n{3,}/', "\n\n", $content);

        return trim($content);
    }

    private function normalizeWorkplaceType(string $type): ?string
    {
        return match (strtolower(trim($type))) {
            'remote' => 'Remoto',
            'hybrid' => 'Híbrido',
            'on-site' => 'Presencial',
            default => null,
        };
    }

    private function normalizeEmploymentType(string $type): ?string
    {
        $type = trim($type);

        if ($type === '') {
            return null;
        }

        return match (strtolower($type)) {
            'full-time', 'full time', 'fulltime' => 'Tempo integral',
            'part-time', 'part time' => 'Meio período',
            'contract', 'contractor' => 'Contrato',
            'internship', 'intern' => 'Estágio',
            default => $type,
        };
    }

    private function normalizePublicationDate(mixed $timestamp): ?string
    {
        if (!is_numeric($timestamp)) {
            return null;
        }

        $timestamp = (int) $timestamp;

        // A Lever fornece createdAt em milissegundos.
        if ($timestamp <= 0 || $timestamp < 1000000000000) {
            return null;
        }

        try {
            return CarbonImmutable::createFromTimestampMs(
                $timestamp,
                'UTC'
            )->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeSalary(array $posting): ?string
    {
        $salary = $posting['salaryRange'] ?? null;

        if (!is_array($salary)) {
            return null;
        }

        $minimum = $salary['min'] ?? null;
        $maximum = $salary['max'] ?? null;
        $currency = trim($salary['currency'] ?? '');
        $interval = trim($salary['interval'] ?? '');

        if ($minimum === null && $maximum === null) {
            return null;
        }

        $range = implode(' - ', array_filter([
            $minimum !== null ? (string) $minimum : null,
            $maximum !== null ? (string) $maximum : null,
        ]));

        return trim(implode(' ', array_filter([
            $currency,
            $range,
            $interval,
        ])));
    }

    private function isValidUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
