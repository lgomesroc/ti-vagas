<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Services\VacancyLocationFilter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class SolidesSource implements VacancySource
{
    private const API_BASE_URL = 'https://apigw.solides.com.br/jobs/v3';

    private readonly VacancyLocationFilter $locationFilter;

    public function __construct(
        private readonly string $companyKey,
        private readonly string $companyName,
        private readonly string $slug,
        private readonly string $careerPageUrl,
        private readonly ?string $caBundle = null,
        private readonly int $maxPages = 20,
        private readonly int $pageSize = 25,
        ?VacancyLocationFilter $locationFilter = null,
    ) {
        $this->locationFilter = $locationFilter
            ?? new VacancyLocationFilter();
    }

    public function name(): string
    {
        return 'solides:' . $this->companyKey;
    }

    public function fetch(): iterable
    {
        $client = Http::acceptJson()
            ->timeout(45)
            ->withOptions([
                'verify' => $this->caBundle ?: true,
            ])
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; TI-Vagas/1.0)',
            ]);

        $vacancies = [];
        $take = min(max($this->pageSize, 1), 25);
        $totalPages = null;

        for ($page = 1; $page <= $this->maxPages; $page++) {
            $response = $client->get(
                self::API_BASE_URL . '/companies/'
                    . rawurlencode($this->slug)
                    . '/vacancies',
                [
                    'take' => $take,
                    'page' => $page,
                ]
            );

            $response->throw();
            $payload = $response->json();

            if (
                !is_array($payload)
                || !isset($payload['data'])
                || !is_array($payload['data'])
            ) {
                throw new RuntimeException(
                    "A API da Sólides retornou uma resposta inválida para {$this->companyName}."
                );
            }

            $totalPages ??= max(
                1,
                (int) ($payload['totalPages'] ?? 1)
            );

            foreach ($payload['data'] as $job) {
                if (!is_array($job)) {
                    continue;
                }

                $vacancy = $this->normalizeVacancy($job);

                if ($vacancy !== null) {
                    $vacancies[] = $vacancy;
                }
            }

            if (
                $page >= $totalPages
                || count($payload['data']) < $take
            ) {
                break;
            }
        }

        return $vacancies;
    }

    private function normalizeVacancy(array $job): ?array
    {
        $id = $job['id'] ?? null;
        $title = $this->cleanText($job['title'] ?? '');
        $description = $this->cleanDescription(
            $job['description'] ?? ''
        );

        if (
            !$id
            || $title === ''
            || $description === ''
            || !empty($job['isHiddenJob'])
            || !empty($job['isDisabled'])
        ) {
            return null;
        }

        if (
            isset($job['currentState'])
            && $job['currentState'] !== 'em_andamento'
        ) {
            return null;
        }

        // createdAt é a data original de publicação fornecida pela API.
        // Sem essa informação, a vaga não pode ser validada pela janela de 60 dias.
        $publishedAt = $this->normalizeDate(
            $job['createdAt'] ?? null
        );

        if (
            $publishedAt === null
            || CarbonImmutable::parse($publishedAt)->lt(
                CarbonImmutable::now('UTC')->subDays(60)->startOfDay()
            )
        ) {
            return null;
        }

        $city = $this->cleanText(
            $job['city']['name'] ?? ''
        );
        $state = $this->cleanText(
            $job['state']['name'] ?? ''
        );
        $stateCode = strtoupper($this->cleanText(
            $job['state']['code'] ?? ''
        ));
        $country = $this->cleanText(
            $job['address']['country']['name'] ?? 'Brasil'
        );

        $workModel = $this->normalizeWorkModel(
            $job['jobType'] ?? null,
            $job['homeOffice'] ?? false
        );

        $location = implode(', ', array_filter([
            $city,
            $stateCode !== '' ? $stateCode : $state,
            $country,
        ]));

        if (!$this->locationFilter->isEligibleForCollection(
            $location,
            $title,
            $description,
            $workModel
        )) {
            return null;
        }

        $applicationUrl = rtrim($this->careerPageUrl, '/')
            . '/vaga/'
            . rawurlencode((string) $id);

        return [
            'title' => $title,
            'company' => $this->companyName,
            'description' => $this->appendStructuredDetails(
                $description,
                $job
            ),
            'location' => $location !== '' ? $location : null,
            'work_model' => $workModel,
            'employment_type' => $this->normalizeEmployment(
                $job['recruitmentContractType'] ?? []
            ),
            'salary' => $this->normalizeSalary(
                $job['salary'] ?? []
            ),
            'application_url' => $applicationUrl,
            'external_id' => $this->name() . ':' . $id,
            'original_published_at' => $publishedAt,

            'city' => $city !== '' ? $city : null,
            'state' => $state !== '' ? $state : null,
            'state_code' => preg_match('/^[A-Z]{2}$/', $stateCode)
                ? $stateCode
                : null,
            'country' => $country !== '' ? $country : 'Brasil',
            'country_code' => 'BR',
            'latitude' => isset($job['address']['latitude'])
                && is_numeric($job['address']['latitude'])
                    ? (float) $job['address']['latitude']
                    : null,
            'longitude' => isset($job['address']['longitude'])
                && is_numeric($job['address']['longitude'])
                    ? (float) $job['address']['longitude']
                    : null,
        ];
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(
                $value,
                'UTC'
            )->startOfDay()->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeWorkModel(
        mixed $jobType,
        mixed $homeOffice
    ): ?string {
        $type = Str::ascii(
            mb_strtolower($this->cleanText($jobType ?? ''))
        );

        return match ($type) {
            'presencial' => 'Presencial',
            'hibrido' => 'Híbrido',
            'remoto' => 'Remoto',
            default => $homeOffice ? 'Remoto' : null,
        };
    }

    private function normalizeEmployment(mixed $types): ?string
    {
        if (!is_array($types)) {
            return null;
        }

        $names = [];

        foreach ($types as $type) {
            $name = $this->cleanText(
                is_array($type) ? ($type['name'] ?? '') : $type
            );

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names !== [] ? implode(', ', array_unique($names)) : null;
    }

    private function normalizeSalary(mixed $salary): ?string
    {
        if (
            !is_array($salary)
            || empty($salary['showRangeToApplicant'])
        ) {
            return null;
        }

        $initial = $salary['initialRange'] ?? null;
        $final = $salary['finalRange'] ?? null;

        if (!is_numeric($initial) && !is_numeric($final)) {
            return null;
        }

        $format = static fn ($value): string => 'R$ '
            . number_format((float) $value, 0, ',', '.');

        if (is_numeric($initial) && is_numeric($final)) {
            return $format($initial) . ' - ' . $format($final);
        }

        return $format(is_numeric($initial) ? $initial : $final);
    }

    private function appendStructuredDetails(
        string $description,
        array $job
    ): string {
        $sections = [];

        foreach ([
            'hardSkills' => 'Competências',
            'benefits' => 'Benefícios',
        ] as $field => $label) {
            $items = [];

            foreach (($job[$field] ?? []) as $item) {
                $name = $this->cleanText(
                    is_array($item) ? ($item['name'] ?? '') : $item
                );

                if ($name !== '') {
                    $items[] = $name;
                }
            }

            if ($items !== []) {
                $sections[] = $label . ":\n- "
                    . implode("\n- ", array_unique($items));
            }
        }

        return $sections !== []
            ? $description . "\n\n" . implode("\n\n", $sections)
            : $description;
    }

    private function cleanDescription(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = html_entity_decode(
            $value,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $value = preg_replace(
            '/<\s*br\s*\/?>|<\/\s*p\s*>|<\/\s*div\s*>|<\/\s*h[1-6]\s*>/i',
            "\n",
            $value
        );
        $value = preg_replace('/<\s*li\b[^>]*>/i', "\n- ", $value);
        $value = strip_tags($value);
        $value = html_entity_decode(
            $value,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
        $value = preg_replace('/[ \t]+/', ' ', $value);
        $value = preg_replace('/ *\n */', "\n", $value);
        $value = preg_replace('/\n{3,}/', "\n\n", $value);

        return trim($value);
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
}
