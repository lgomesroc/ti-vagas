<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Services\VacancyLocationFilter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class OracleHcmSource implements VacancySource
{
    private const BASE_URL = 'https://iaziqy.fa.ocs.oraclecloud.com';

    private const PAGE_SIZE = 100;

    private readonly VacancyLocationFilter $locationFilter;

    public function __construct(
        private readonly string $siteNumber,
        private readonly string $siteName,
        private readonly string $companyName,
        private readonly ?string $caBundle = null,
        private readonly int $maxJobs = 1000,
        ?VacancyLocationFilter $locationFilter = null,
    ) {
        $this->locationFilter = $locationFilter
            ?? new VacancyLocationFilter();
    }

    public function name(): string
    {
        return 'oracle-hcm-' . Str::slug($this->companyName);
    }

    public function fetch(): iterable
    {
        $vacancies = [];
        $offset = 0;
        $processed = 0;

        while ($processed < $this->maxJobs) {
            $limit = min(
                self::PAGE_SIZE,
                $this->maxJobs - $processed
            );

            $url = self::BASE_URL .
                '/hcmRestApi/resources/latest/recruitingCEJobRequisitions' .
                '?onlyData=true' .
                '&expand=requisitionList' .
                '&finder=findReqs;siteNumber=' .
                rawurlencode($this->siteNumber) .
                ',limit=' . $limit .
                ',offset=' . $offset;

            $response = Http::acceptJson()
                ->timeout(60)
                ->withHeaders([
                    'User-Agent' => 'TI-Vagas/1.0',
                ])
                ->withOptions([
                    'verify' => $this->caBundle ?: true,
                ])
                ->get($url);

            $response->throw();

            $items = $response->json('items');

            if (!is_array($items) || !isset($items[0])) {
                throw new RuntimeException(
                    'Resposta inválida recebida da API Oracle HCM.'
                );
            }

            $postings = $items[0]['requisitionList'] ?? [];

            if (!is_array($postings) || $postings === []) {
                break;
            }

            foreach ($postings as $posting) {
                $processed++;

                if (!is_array($posting)) {
                    continue;
                }

                if (!$this->isBrazilianPosting($posting)) {
                    continue;
                }

                $id = trim((string) ($posting['Id'] ?? ''));

                if ($id === '') {
                    continue;
                }

                $detail = $this->fetchDetails($id);

                if ($detail === null) {
                    continue;
                }

                $vacancy = $this->normalizePosting(
                    $posting,
                    $detail
                );

                if ($vacancy !== null) {
                    $vacancies[] = $vacancy;
                }
            }

            $offset += count($postings);

            if (count($postings) < $limit) {
                break;
            }
        }

        return $vacancies;
    }

    private function fetchDetails(string $id): ?array
    {
        $url = self::BASE_URL .
            '/hcmRestApi/resources/latest/recruitingCEJobRequisitionDetails' .
            '?onlyData=true' .
            '&finder=ById;Id="' . rawurlencode($id) .
            '",siteNumber=' . rawurlencode($this->siteNumber);

        $response = Http::acceptJson()
            ->timeout(60)
            ->withHeaders([
                'User-Agent' => 'TI-Vagas/1.0',
            ])
            ->withOptions([
                'verify' => $this->caBundle ?: true,
            ])
            ->get($url);

        $response->throw();

        $items = $response->json('items');

        if (!is_array($items) || !isset($items[0])) {
            return null;
        }

        return $items[0];
    }

    private function isBrazilianPosting(array $posting): bool
    {
        $location = $this->normalize(
            $posting['PrimaryLocation'] ?? ''
        );

        $country = strtoupper(trim(
            (string) ($posting['PrimaryLocationCountry'] ?? '')
        ));

        return $country === 'BR'
            || str_contains($location, 'brazil')
            || str_contains($location, 'brasil');
    }

    private function normalizePosting(
        array $posting,
        array $detail
    ): ?array {
        $id = trim((string) ($detail['Id'] ?? $posting['Id'] ?? ''));
        $title = trim((string) ($detail['Title'] ?? $posting['Title'] ?? ''));

        if ($id === '' || $title === '') {
            return null;
        }

        if (!$this->hasJuniorSignal($title, $detail)) {
            return null;
        }

        if ($this->hasSeniorTitle($title)) {
            return null;
        }

        if (!$this->isRelevantTechnologyRole($title)) {
            return null;
        }

        $description = $this->cleanDescription(
            (string) ($detail['ExternalDescriptionStr'] ?? '')
        );

        if ($description === '') {
            return null;
        }

        $location = trim((string) (
            $detail['PrimaryLocation']
                ?? $posting['PrimaryLocation']
                ?? ''
        ));

        $country = strtoupper(trim((string) (
            $detail['PrimaryLocationCountry'] ?? ''
        )));

        if (
            $country !== 'BR'
            && !$this->isBrazilianPosting([
                'PrimaryLocation' => $location,
                'PrimaryLocationCountry' => $country,
            ])
        ) {
            return null;
        }

        $workModel = $this->normalizeWorkModel(
            $detail,
            $title,
            $location,
            $description
        );

        if (!$this->locationFilter->isEligible(
            $location,
            $title,
            $description,
            $workModel
        )) {
            return null;
        }

        $publishedAt = $this->normalizePublicationDate(
            $detail['ExternalPostedStartDate']
                ?? $posting['PostedDate']
                ?? null
        );

        if ($publishedAt === null) {
            return null;
        }

        $employmentType = $this->normalizeEmploymentType(
            (string) ($detail['JobSchedule'] ?? '')
        );

        return [
            'title' => $title,
            'company' => $this->companyName,
            'description' => $this->appendMetadata(
                $description,
                $detail
            ),
            'location' => $location !== '' ? $location : null,
            'work_model' => $workModel,
            'employment_type' => $employmentType,
            'salary' => null,
            'application_url' => $this->buildApplicationUrl($id),
            'external_id' => $id,
            'original_published_at' => $publishedAt,
        ];
    }

    private function hasJuniorSignal(
        string $title,
        array $detail
    ): bool {
        $text = $this->normalize(
            $title . ' ' .
            ($detail['ExternalDescriptionStr'] ?? '')
        );

        return preg_match(
            '/\b(junior|jr\.?|estagio|estagiario|intern|internship)\b/',
            $text
        ) === 1;
    }

    private function hasSeniorTitle(string $title): bool
    {
        return preg_match(
            '/\b(pleno|mid[- ]senior|senior|sr\.?|staff|principal|lead|manager|director|head)\b/i',
            $title
        ) === 1;
    }

    private function isRelevantTechnologyRole(string $title): bool
    {
        $normalized = $this->normalize($title);

        return preg_match(
            '/\b(software|developer|desenvolvedor|programmer|programador|backend|back-end|frontend|front-end|full[\s-]?stack|devops|cloud|infrastructure|infraestrutura|technical support|it support|suporte tecnico|systems engineer|integration engineer|mobile engineer|web developer|data engineer)\b/',
            $normalized
        ) === 1;
    }

    private function normalizeWorkModel(
        array $detail,
        string $title,
        string $location,
        string $description
    ): ?string {
        $workplace = $this->normalize(
            (string) (
                $detail['WorkplaceType']
                    ?? $detail['WorkplaceTypeCode']
                    ?? ''
            )
        );

        if (str_contains($workplace, 'remote')) {
            return 'Remoto';
        }

        if (str_contains($workplace, 'hybrid')) {
            return 'Híbrido';
        }

        if (
            str_contains($workplace, 'on site')
            || str_contains($workplace, 'onsite')
        ) {
            return 'Presencial';
        }

        return $this->locationFilter->classifyWorkModel(
            $title,
            $location,
            $description
        );
    }

    private function buildApplicationUrl(string $id): string
    {
        return self::BASE_URL .
            '/hcmUI/CandidateExperience/en/sites/' .
            rawurlencode($this->siteName) .
            '/job/' . rawurlencode($id);
    }

    private function normalizeEmploymentType(string $schedule): ?string
    {
        $normalized = $this->normalize($schedule);

        return match ($normalized) {
            'full time', 'full-time', 'fulltime' => 'Tempo integral',
            'part time', 'part-time' => 'Meio período',
            'temporary' => 'Temporário',
            'internship', 'intern' => 'Estágio',
            default => trim($schedule) !== '' ? trim($schedule) : null,
        };
    }

    private function normalizePublicationDate(mixed $date): ?string
    {
        if (!is_string($date) || trim($date) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($date)
                ->utc()
                ->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function appendMetadata(
        string $description,
        array $detail
    ): string {
        $metadata = [];

        $category = trim((string) ($detail['Category'] ?? ''));
        $schedule = trim((string) ($detail['JobSchedule'] ?? ''));
        $location = trim((string) ($detail['PrimaryLocation'] ?? ''));

        if ($category !== '') {
            $metadata[] = 'Categoria: ' . $category;
        }

        if ($schedule !== '') {
            $metadata[] = 'Jornada: ' . $schedule;
        }

        if ($location !== '') {
            $metadata[] = 'Localidade informada pela empresa: ' . $location;
        }

        return $metadata === []
            ? $description
            : $description . "\n\n" . implode("\n", $metadata);
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

    private function normalize(?string $value): string
    {
        return Str::ascii(mb_strtolower($value ?? ''));
    }
}