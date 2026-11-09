<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Services\VacancyLocationFilter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class WorkableSource implements VacancySource
{
    private const BASE_URL = 'https://apply.workable.com/api/v1/widget/accounts';

    private readonly VacancyLocationFilter $locationFilter;

    public function __construct(
        private readonly string $account,
        private readonly string $companyName,
        private readonly ?string $caBundle = null,
        private readonly int $maxJobs = 200,
        ?VacancyLocationFilter $locationFilter = null,
    ) {
        $this->locationFilter = $locationFilter
            ?? new VacancyLocationFilter();
    }

    public function name(): string
    {
        return 'workable-' . $this->account;
    }

    public function fetch(): iterable
    {
        $response = Http::acceptJson()
            ->timeout(60)
            ->withHeaders([
                'User-Agent' => 'TI-Vagas/1.0',
            ])
            ->withOptions([
                'verify' => $this->caBundle ?: true,
            ])
            ->get(
                self::BASE_URL . '/' . rawurlencode($this->account),
                ['details' => 'true']
            );

        $response->throw();

        $payload = $response->json();
        $postings = $payload['jobs'] ?? null;

        if (!is_array($postings)) {
            throw new RuntimeException(
                'Resposta inválida recebida da API Workable: ' .
                $this->account
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
        $title = trim($posting['title'] ?? '');
        $shortcode = trim($posting['shortcode'] ?? '');

        if ($title === '' || $shortcode === '') {
            return null;
        }

        if ($this->hasExcludedExperience(
            $posting['experience'] ?? ''
        )) {
            return null;
        }

        $description = $this->cleanDescription(
            $posting['description'] ?? ''
        );

        if ($description === '') {
            return null;
        }

        $publishedAt = $this->normalizePublicationDate(
            $posting['published_on'] ?? null
        );

        if ($publishedAt === null) {
            return null;
        }

        $locations = $this->extractLocations($posting);

        $country = trim($posting['country'] ?? '');

        if (!$this->isBrazil($country) && !$this->hasBrazilLocation($locations)) {
            return null;
        }

        $workModel = $this->normalizeWorkModel(
            $posting['telecommuting'] ?? null,
            $title,
            $description
        );

        $location = $this->buildLocation(
            $posting,
            $locations,
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
            $posting['application_url']
                ?? $posting['url']
                ?? ''
        );

        if (!$this->isValidUrl($applicationUrl)) {
            $applicationUrl = trim($posting['url'] ?? '');
        }

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
            'work_model' => $workModel,
            'employment_type' => $this->normalizeEmploymentType(
                $posting['employment_type'] ?? ''
            ),
            'salary' => $this->normalizeSalary($posting),
            'application_url' => $applicationUrl,
            'external_id' => $shortcode,
            'original_published_at' => $publishedAt,
        ];
    }

    private function extractLocations(array $posting): array
    {
        $locations = $posting['locations'] ?? [];

        if (!is_array($locations)) {
            return [];
        }

        return array_values(array_filter(
            $locations,
            fn ($location) => is_array($location)
        ));
    }

    private function hasBrazilLocation(array $locations): bool
    {
        foreach ($locations as $location) {
            if (
                $this->isBrazil($location['country'] ?? '') ||
                strtoupper(trim($location['countryCode'] ?? '')) === 'BR'
            ) {
                return true;
            }
        }

        return false;
    }

    private function isBrazil(string $country): bool
    {
        $normalized = Str::ascii(mb_strtolower(trim($country)));

        return in_array($normalized, [
            'br',
            'brasil',
            'brazil',
        ], true);
    }

    private function buildLocation(
        array $posting,
        array $locations,
        ?string $workModel
    ): string {
        if ($workModel === 'Remoto') {
            return 'Brasil (remoto)';
        }

        $parts = [];

        foreach ($locations as $location) {
            $city = trim($location['city'] ?? '');
            $region = trim($location['region'] ?? '');
            $country = trim($location['country'] ?? '');

            $parts[] = implode(', ', array_filter([
                $city,
                $region,
                $country,
            ]));
        }

        if ($parts === []) {
            $parts[] = implode(', ', array_filter([
                trim($posting['city'] ?? ''),
                trim($posting['state'] ?? ''),
                trim($posting['country'] ?? ''),
            ]));
        }

        return implode(' / ', array_unique(array_filter($parts)));
    }

    private function normalizeWorkModel(
        mixed $telecommuting,
        string $title,
        string $description
    ): ?string {
        if ($telecommuting === true) {
            return 'Remoto';
        }

        return $this->locationFilter->classifyWorkModel(
            $title,
            null,
            $description
        );
    }

    private function hasExcludedExperience(string $experience): bool
    {
        $normalized = Str::ascii(
            mb_strtolower(trim($experience))
        );

        if ($normalized === '') {
            return false;
        }

        return preg_match(
            '/\b(pleno|mid[- ]senior|senior|sr|staff|principal|lead|manager|director|executive|head)\b/',
            $normalized
        ) === 1;
    }

    private function normalizeEmploymentType(string $type): ?string
    {
        $normalized = Str::ascii(
            mb_strtolower(trim($type))
        );

        return match ($normalized) {
            'full-time', 'full time', 'fulltime' => 'Tempo integral',
            'part-time', 'part time' => 'Meio período',
            'contract', 'contractor' => 'Contrato',
            'internship', 'intern' => 'Estágio',
            default => trim($type) !== '' ? trim($type) : null,
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

    private function normalizeSalary(array $posting): ?string
    {
        $salary = $posting['salary'] ?? null;

        if (is_string($salary) && trim($salary) !== '') {
            return trim($salary);
        }

        if (is_numeric($salary)) {
            return (string) $salary;
        }

        return null;
    }

    private function appendMetadata(
        string $description,
        array $posting
    ): string {
        $metadata = [];

        $department = trim($posting['department'] ?? '');
        $function = trim($posting['function'] ?? '');
        $experience = trim($posting['experience'] ?? '');

        if ($department !== '') {
            $metadata[] = 'Área: ' . $department;
        }

        if ($function !== '') {
            $metadata[] = 'Função: ' . $function;
        }

        if ($experience !== '') {
            $metadata[] = 'Experiência informada pela empresa: ' .
                $experience;
        }

        if ($metadata === []) {
            return $description;
        }

        return $description . "\n\n" .
            implode("\n", $metadata);
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

    private function isValidUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
