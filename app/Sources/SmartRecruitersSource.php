<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Services\VacancyLocationFilter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class SmartRecruitersSource implements VacancySource
{
    private const BASE_URL = 'https://api.smartrecruiters.com/v1/companies';
    private const PAGE_SIZE = 100;

    private readonly VacancyLocationFilter $locationFilter;

    public function __construct(
        private readonly string $companyIdentifier,
        private readonly string $companyName,
        private readonly ?string $caBundle = null,
        private readonly int $maxJobs = 500,
        ?VacancyLocationFilter $locationFilter = null,
    ) {
        $this->locationFilter = $locationFilter ?? new VacancyLocationFilter();
    }

    public function name(): string
    {
        return 'smartrecruiters-' . $this->companyIdentifier;
    }

    public function fetch(): iterable
    {
        $endpoint = self::BASE_URL . '/' .
            rawurlencode($this->companyIdentifier) . '/postings';

        $vacancies = [];
        $offset = 0;
        $processed = 0;
        $totalFound = null;

        while ($processed < $this->maxJobs) {
            $limit = min(self::PAGE_SIZE, $this->maxJobs - $processed);

            $response = Http::acceptJson()
                ->timeout(60)
                ->withHeaders(['User-Agent' => 'TI-Vagas/1.0'])
                ->withOptions(['verify' => $this->caBundle ?: true])
                ->get($endpoint, [
                    'limit' => $limit,
                    'offset' => $offset,
                    'destination' => 'PUBLIC',
                ]);

            $response->throw();
            $payload = $response->json();
            $postings = $payload['content'] ?? null;

            if (!is_array($postings)) {
                throw new RuntimeException(
                    'Resposta inválida recebida da API SmartRecruiters: ' .
                    $this->companyIdentifier
                );
            }

            $totalFound = is_numeric($payload['totalFound'] ?? null)
                ? (int) $payload['totalFound']
                : null;

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

                $id = trim((string) ($posting['id'] ?? $posting['uuid'] ?? ''));

                if ($id === '') {
                    continue;
                }

                $detailResponse = Http::acceptJson()
                    ->timeout(60)
                    ->withHeaders(['User-Agent' => 'TI-Vagas/1.0'])
                    ->withOptions(['verify' => $this->caBundle ?: true])
                    ->get($endpoint . '/' . rawurlencode($id));

                $detailResponse->throw();
                $details = $detailResponse->json();

                if (!is_array($details)) {
                    continue;
                }

                $vacancy = $this->normalizePosting($details);

                if ($vacancy !== null) {
                    $vacancies[] = $vacancy;
                }
            }

            $offset += count($postings);

            if (
                count($postings) < $limit ||
                ($totalFound !== null && $offset >= $totalFound)
            ) {
                break;
            }
        }

        return $vacancies;
    }

    private function normalizePosting(array $posting): ?array
    {
        if (($posting['active'] ?? true) !== true) {
            return null;
        }

        $id = trim((string) ($posting['id'] ?? $posting['uuid'] ?? ''));
        $title = trim((string) ($posting['name'] ?? ''));

        if ($id === '' || $title === '') {
            return null;
        }

        $locationData = $posting['location'] ?? [];
        if (!is_array($locationData)) {
            $locationData = [];
        }

        $country = strtoupper(trim((string) (
            $locationData['country'] ?? $locationData['countryCode'] ?? ''
        )));
        $remote = ($locationData['remote'] ?? false) === true;

        if ($country !== 'BR') {
            return null;
        }

        $city = trim((string) ($locationData['city'] ?? ''));
        $state = trim((string) ($locationData['region'] ?? ''));
        $workModel = $this->normalizeWorkModel($locationData, $title);

        $location = $workModel === 'Remoto'
            ? 'Brasil (remoto)'
            : implode(', ', array_filter([$city, $state, 'Brasil']));

        $sections = $posting['jobAd']['sections'] ?? [];
        if (!is_array($sections)) {
            $sections = [];
        }

        $descriptionParts = [];
        foreach ([
            'jobDescription',
            'qualifications',
            'additionalInformation',
            'companyDescription',
        ] as $sectionKey) {
            $section = $sections[$sectionKey] ?? [];
            $content = is_array($section) ? ($section['text'] ?? '') : '';
            if (is_string($content) && trim($content) !== '') {
                $descriptionParts[] = $this->cleanDescription($content);
            }
        }

        $description = trim(implode("\n\n", array_filter($descriptionParts)));
        if ($description === '') {
            return null;
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
            $posting['releasedDate'] ?? null
        );
        if ($publishedAt === null) {
            return null;
        }

        $applicationUrl = trim((string) (
            $posting['applyUrl'] ?? $posting['postingUrl'] ?? ''
        ));
        if (!$this->isValidUrl($applicationUrl)) {
            return null;
        }

        return [
            'title' => $title,
            'company' => $this->companyName,
            'description' => $description,
            'location' => $location !== '' ? $location : null,
            'city' => $city !== '' ? $city : null,
            'state' => $state !== '' ? $state : null,
            'country' => 'BR',
            'work_model' => $workModel,
            'employment_type' => $this->normalizeEmploymentType(
                $posting['typeOfEmployment']['label'] ?? ''
            ),
            'salary' => null,
            'application_url' => $applicationUrl,
            'external_id' => $this->companyIdentifier . ':' . $id,
            'original_published_at' => $publishedAt,
        ];
    }

    private function normalizeWorkModel(array $location, string $title): ?string
    {
        $type = Str::ascii(mb_strtolower(trim((string) (
            $location['type'] ?? $location['locationType'] ?? ''
        ))));

        if (in_array($type, ['remote'], true) || ($location['remote'] ?? false) === true) {
            return 'Remoto';
        }

        if ($type === 'hybrid') {
            return 'Híbrido';
        }

        if (in_array($type, ['onsite', 'on-site'], true)) {
            return 'Presencial';
        }

        return $this->locationFilter->classifyWorkModel($title, null, null);
    }

    private function normalizeEmploymentType(string $type): ?string
    {
        $type = trim($type);
        if ($type === '') {
            return null;
        }

        return match (Str::ascii(mb_strtolower($type))) {
            'full-time', 'full time', 'fulltime', 'permanent' => 'Tempo integral',
            'part-time', 'part time', 'parttime' => 'Meio período',
            'contract', 'contractor', 'temporary' => 'Contrato',
            'intern', 'internship' => 'Estágio',
            default => $type,
        };
    }

    private function normalizePublicationDate(mixed $date): ?string
    {
        if (!is_string($date) || trim($date) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($date)->utc()->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function cleanDescription(string $content): string
    {
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $content = preg_replace(
            '/<\s*br\s*\/?>|<\/\s*p\s*>|<\/\s*div\s*>|<\/\s*h[1-6]\s*>/i',
            "\n",
            $content
        );
        $content = preg_replace('/<\s*li\b[^>]*>/i', "\n- ", $content);
        $content = strip_tags($content);
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $content = preg_replace('/[ \t]+/', ' ', $content);
        $content = preg_replace('/ *\n */', "\n", $content);
        $content = preg_replace('/\n{3,}/', "\n\n", $content);

        return trim($content);
    }

    private function isValidUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
