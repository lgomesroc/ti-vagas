<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Services\GeoLocationNormalizer;
use App\Services\VacancyLocationFilter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class ZohoRecruitSource implements VacancySource
{
    private const BASE_URL = 'https://%s.zohorecruit.com/recruit/v2/public/Job_Openings';

    private readonly VacancyLocationFilter $locationFilter;

    private readonly GeoLocationNormalizer $geoNormalizer;

    public function __construct(
        private readonly string $subdomain,
        private readonly string $pageName,
        private readonly string $companyName,
        private readonly ?string $remoteCountry = null,
        private readonly ?string $caBundle = null,
        private readonly int $maxJobs = 200,
        ?VacancyLocationFilter $locationFilter = null,
        ?GeoLocationNormalizer $geoNormalizer = null,
    ) {
        $this->locationFilter = $locationFilter
            ?? new VacancyLocationFilter();

        $this->geoNormalizer = $geoNormalizer
            ?? new GeoLocationNormalizer();
    }

    public function name(): string
    {
        return 'zoho-recruit-' . $this->subdomain;
    }

    public function fetch(): iterable
    {
        $url = sprintf(
            self::BASE_URL,
            rawurlencode($this->subdomain)
        );

        $response = Http::acceptJson()
            ->timeout(60)
            ->withHeaders([
                'User-Agent' => 'TI-Vagas/1.0',
            ])
            ->withOptions([
                'verify' => $this->caBundle ?: true,
            ])
            ->get($url, [
                'pagename' => $this->pageName,
            ]);

        $response->throw();

        $payload = $response->json();
        $postings = $payload['data'] ?? null;

        if (!is_array($postings)) {
            throw new RuntimeException(
                'Resposta inválida recebida da API Zoho Recruit: ' .
                $this->subdomain
            );
        }

        $vacancies = [];

        foreach (array_slice($postings, 0, $this->maxJobs) as $posting) {
            if (!is_array($posting)) {
                continue;
            }

            $vacancy = $this->normalizePosting($posting);

            if ($vacancy !== null) {
                $vacancies[] = $vacancy;
            }
        }

        return $vacancies;
    }

    private function normalizePosting(array $posting): ?array
    {
        if (!$this->isPublished($posting['Publish'] ?? null)) {
            return null;
        }

        $id = trim((string) ($posting['id'] ?? ''));
        $title = $this->cleanText($posting['Posting_Title'] ?? '');

        if ($id === '' || $title === '') {
            return null;
        }

        $description = $this->cleanDescription(
            $posting['Job_Description'] ?? ''
        );

        if ($description === '') {
            return null;
        }

        $publishedAt = $this->normalizePublicationDate(
            $posting['Date_Opened'] ?? null
        );

        if ($publishedAt === null) {
            return null;
        }

        $isRemote = $this->isRemote($posting['Remote_Job'] ?? null);

        $city = $this->cleanText($posting['City'] ?? '');
        $state = $this->cleanText($posting['State'] ?? '');
        $country = $this->cleanText($posting['Country'] ?? '');

        if ($isRemote && $country === '') {
            $country = $this->cleanText($this->remoteCountry ?? '');
        }

        $country = $this->normalizeCountryName($country);

        $geography = $this->geoNormalizer->normalize([
            'city' => $city,
            'state' => $state,
            'country' => $country,
        ]);

        $workModel = $isRemote
            ? 'Remoto'
            : $this->locationFilter->classifyWorkModel(
                $title,
                null,
                $description
            );

        $location = $this->buildLocation(
            $city,
            $geography['state'],
            $country,
            $workModel
        );

        if (!$this->locationFilter->isEligibleForCollection(
            $location,
            $title,
            $description,
            $workModel
        )) {
            return null;
        }

        $applicationUrl = trim(
            (string) ($posting['$url'] ?? '')
        );

        if (!$this->isValidUrl($applicationUrl)) {
            return null;
        }

        return [
            'title' => $title,
            'company' => $this->companyName,
            'description' => $this->appendMetadata(
                $description,
                $posting
            ),
            'location' => $location !== '' ? $location : null,
            'city' => $geography['city'],
            'state' => $geography['state'],
            'state_code' => $geography['state_code'],
            'country' => $country !== '' ? $country : null,
            'country_code' => $geography['country_code'],
            'work_model' => $workModel,
            'employment_type' => $this->normalizeEmploymentType(
                $posting['Job_Type'] ?? ''
            ),
            'salary' => null,
            'application_url' => $applicationUrl,
            'external_id' => $id,
            'original_published_at' => $publishedAt,
        ];
    }

    private function isPublished(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            return in_array(
                mb_strtolower(trim($value)),
                ['true', '1', 'sim', 'yes'],
                true
            );
        }

        return false;
    }

    private function isRemote(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(
            mb_strtolower(trim((string) $value)),
            ['sim', 'true', '1', 'yes'],
            true
        );
    }

    private function normalizeCountryName(?string $country): string
    {
        $country = $this->cleanText($country);

        if ($country === '') {
            return '';
        }

        $normalized = Str::ascii(
            mb_strtolower(trim($country))
        );

        if (in_array($normalized, [
            'br',
            'bra',
            'brasil',
            'brazil',
        ], true)) {
            return 'Brasil';
        }

        return $country;
    }

    private function buildLocation(
        string $city,
        ?string $state,
        string $country,
        ?string $workModel
    ): string {
        if ($workModel === 'Remoto') {
            return $country !== ''
                ? $country . ' (remoto)'
                : '';
        }

        return implode(', ', array_filter([
            $city,
            $state,
            $country,
        ]));
    }

    private function normalizeEmploymentType(string $type): ?string
    {
        $normalized = Str::ascii(
            mb_strtolower(trim($type))
        );

        return match ($normalized) {
            'full time', 'full-time', 'fulltime', 'efetivo' => 'Tempo integral',
            'contract', 'contractor' => 'Contrato',
            'part time', 'part-time' => 'Meio período',
            'temporario', 'temporary' => 'Temporário',
            'intern', 'internship', 'estagio' => 'Estágio',
            default => trim($type) !== '' ? trim($type) : null,
        };
    }

    private function normalizePublicationDate(mixed $date): ?string
    {
        if (!is_string($date) || trim($date) === '') {
            return null;
        }

        try {
            $date = trim($date);

            $publishedAt = CarbonImmutable::createFromFormat(
                '!d/m/Y',
                $date,
                'UTC'
            );

            if ($publishedAt === false) {
                return null;
            }

            $errors = CarbonImmutable::getLastErrors();

            if (
                is_array($errors)
                && (
                    $errors['warning_count'] > 0
                    || $errors['error_count'] > 0
                )
            ) {
                return null;
            }

            return $publishedAt->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function appendMetadata(
        string $description,
        array $posting
    ): string {
        $metadata = [];

        $industry = $this->cleanText($posting['Industry'] ?? '');
        $jobType = $this->cleanText($posting['Job_Type'] ?? '');

        if ($industry !== '') {
            $metadata[] = 'Área informada pela empresa: ' . $industry;
        }

        if ($jobType !== '') {
            $metadata[] = 'Tipo de contratação informado pela empresa: ' .
                $jobType;
        }

        if ($metadata === []) {
            return $description;
        }

        return $description . "\n\n" .
            implode("\n", $metadata);
    }

    private function cleanDescription(mixed $content): string
    {
        if (!is_string($content)) {
            return '';
        }

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

    private function cleanText(mixed $value): string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }

        $value = html_entity_decode(
            strip_tags((string) $value),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    private function isValidUrl(string $url): bool
    {
        return filter_var(
            $url,
            FILTER_VALIDATE_URL
        ) !== false;
    }
}
