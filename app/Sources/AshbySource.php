<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Services\VacancyLocationFilter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class AshbySource implements VacancySource
{
    private const BASE_URL =
        'https://api.ashbyhq.com/posting-api/job-board';

    private readonly VacancyLocationFilter $locationFilter;

    public function __construct(
        private readonly string $board,
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
        return 'ashby-' . $this->board;
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
                self::BASE_URL . '/' . rawurlencode($this->board)
            );

        $response->throw();

        $payload = $response->json();
        $postings = $payload['jobs'] ?? null;

        if (!is_array($postings)) {
            throw new RuntimeException(
                'Resposta inválida recebida da API Ashby: ' .
                $this->board
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
        if (($posting['isListed'] ?? true) !== true) {
            return null;
        }

        $id = trim($posting['id'] ?? '');
        $title = trim($posting['title'] ?? '');

        if ($id === '' || $title === '') {
            return null;
        }

        $description = $this->cleanDescription(
            $posting['descriptionPlain']
                ?? $posting['descriptionHtml']
                ?? ''
        );

        if ($description === '') {
            return null;
        }

        $publishedAt = $this->normalizePublicationDate(
            $posting['publishedAt'] ?? null
        );

        if ($publishedAt === null) {
            return null;
        }

        $address = $posting['address']['postalAddress'] ?? [];

        $country = trim($address['addressCountry'] ?? '');
        $locationText = trim($posting['location'] ?? '');

        if (!$this->isBrazil($country) &&
            !$this->hasBrazilLocation($locationText)) {
            return null;
        }

        $workModel = $this->normalizeWorkModel(
            $posting['workplaceType'] ?? null,
            $posting['isRemote'] ?? false,
            $title,
            $description
        );

        $location = $this->buildLocation(
            $address,
            $locationText,
            $workModel
        );

        if (!$this->locationFilter->isEligible(
            $location,
            $title,
            $description,
            $workModel
        )) {
            return null;
        }

        $applicationUrl = trim(
            $posting['applyUrl']
                ?? $posting['jobUrl']
                ?? ''
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
            'work_model' => $workModel,
            'employment_type' => $this->normalizeEmploymentType(
                $posting['employmentType'] ?? ''
            ),
            'salary' => null,
            'application_url' => $applicationUrl,
            'external_id' => $id,
            'original_published_at' => $publishedAt,
        ];
    }

    private function isBrazil(string $country): bool
    {
        $normalized = Str::ascii(
            mb_strtolower(trim($country))
        );

        return in_array($normalized, [
            'br',
            'brasil',
            'brazil',
        ], true);
    }

    private function hasBrazilLocation(string $location): bool
    {
        $normalized = Str::ascii(
            mb_strtolower(trim($location))
        );

        return str_contains($normalized, 'brasil')
            || str_contains($normalized, 'brazil');
    }

    private function normalizeWorkModel(
        ?string $workplaceType,
        bool $isRemote,
        string $title,
        string $description
    ): ?string {
        $normalized = Str::ascii(
            mb_strtolower(trim($workplaceType ?? ''))
        );

        if ($normalized === 'remote') {
            return 'Remoto';
        }

        if ($normalized === 'hybrid') {
            return 'Híbrido';
        }

        if (in_array($normalized, ['onsite', 'on-site'], true)) {
            return 'Presencial';
        }

        if ($normalized === '' && $isRemote) {
            return 'Remoto';
        }

        return $this->locationFilter->classifyWorkModel(
            $title,
            null,
            $description
        );
    }

    private function buildLocation(
        array $address,
        string $locationText,
        ?string $workModel
    ): string {
        if ($workModel === 'Remoto') {
            return 'Brasil (remoto)';
        }

        $parts = array_filter([
            trim($address['addressLocality'] ?? ''),
            trim($address['addressRegion'] ?? ''),
            trim($address['addressCountry'] ?? ''),
        ]);

        if ($parts !== []) {
            return implode(', ', array_unique($parts));
        }

        return $locationText;
    }

    private function normalizeEmploymentType(string $type): ?string
    {
        $normalized = Str::ascii(
            mb_strtolower(trim($type))
        );

        return match ($normalized) {
            'fulltime', 'full-time', 'full time' => 'Tempo integral',
            'parttime', 'part-time', 'part time' => 'Meio período',
            'contract', 'contractor' => 'Contrato',
            'intern', 'internship' => 'Estágio',
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

    private function appendMetadata(
        string $description,
        array $posting
    ): string {
        $metadata = [];

        $department = trim($posting['department'] ?? '');
        $team = trim($posting['team'] ?? '');

        if ($department !== '') {
            $metadata[] = 'Área: ' . $department;
        }

        if ($team !== '') {
            $metadata[] = 'Equipe: ' . $team;
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
        return filter_var(
            $url,
            FILTER_VALIDATE_URL
        ) !== false;
    }
}
