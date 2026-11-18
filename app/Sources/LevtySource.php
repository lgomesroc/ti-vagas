<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Services\VacancyLocationFilter;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class LevtySource implements VacancySource
{
    private const API_URL = 'https://levty.sydle.one/api/1/site-institucional/com.levty.siteLEVTY/SiteAPI/provideSelectiveProcesses?includeTrainee=true';

    private const CAREER_URL = 'https://www.levty.com/br/carreiras/';

    private readonly VacancyLocationFilter $locationFilter;

    public function __construct(
        private readonly ?string $caBundle = null,
        private readonly int $maxJobs = 500,
        ?VacancyLocationFilter $locationFilter = null,
    ) {
        $this->locationFilter = $locationFilter ?? new VacancyLocationFilter();
    }

    public function name(): string
    {
        return 'levty';
    }

    public function fetch(): iterable
    {
        $response = Http::acceptJson()
            ->timeout(60)
            ->withOptions(['verify' => $this->caBundle ?: true])
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; TI-Vagas/1.0)',
                'Referer' => self::CAREER_URL,
            ])
            ->get(self::API_URL);

        $response->throw();

        $payload = $response->json();
        $processes = is_array($payload)
            ? ($payload['selectiveProcesses'] ?? null)
            : null;

        if (!is_array($processes)) {
            throw new RuntimeException('Resposta inválida da API de carreiras da LEVTY.');
        }

        $vacancies = [];
        $seen = [];

        foreach ($processes as $process) {
            if (!is_array($process)) {
                continue;
            }

            $id = trim((string) ($process['_id'] ?? ''));
            $title = $this->cleanText($process['name'] ?? '');
            $slug = trim((string) ($process['nameForUrl'] ?? ''));

            if (
                $id === ''
                || $title === ''
                || $slug === ''
                || isset($seen[$id])
                || $this->isTrainee($process, $title)
            ) {
                continue;
            }

            $seen[$id] = true;

            $publishedAt = $this->normalizeDate($process['publicationDate'] ?? null);
            if ($publishedAt === null) {
                Log::warning('LEVTY: oportunidade ignorada por não possuir data original válida.', [
                    'process_id' => $id,
                    'title' => $title,
                ]);
                continue;
            }

            $city = $this->cleanText($process['city']['name'] ?? '');
            $state = $this->cleanText($process['city']['state']['initials'] ?? '');
            $location = implode('/', array_filter([$city, $state]));
            if ($location === '') {
                $location = $this->cleanText($process['location'] ?? '');
            }

            $applicationUrl = self::CAREER_URL . rawurlencode($slug);
            $description = $this->fetchDescription($applicationUrl);

            if ($description === '') {
                Log::warning('LEVTY: oportunidade ignorada porque a página oficial não retornou descrição.', [
                    'process_id' => $id,
                    'url' => $applicationUrl,
                ]);
                continue;
            }

            $workModel = ($process['isRemote'] ?? false) === true
                ? 'Remoto'
                : $this->locationFilter->classifyWorkModel($title, $location, $description);

            if (!$this->locationFilter->isEligibleForCollection(
                $location,
                $title,
                $description,
                $workModel
            )) {
                continue;
            }

            $vacancies[] = [
                'title' => $title,
                'company' => 'LEVTY',
                'description' => $description,
                'location' => $location !== '' ? $location : null,
                'work_model' => $workModel,
                'employment_type' => $this->normalizeEmploymentType(
                    $process['employmentType'] ?? null
                ),
                'salary' => null,
                'application_url' => $applicationUrl,
                'external_id' => 'levty:' . $id,
                'original_published_at' => $publishedAt,
            ];

            if (count($vacancies) >= $this->maxJobs) {
                break;
            }
        }

        Log::info('LEVTY: coleta concluída.', [
            'processes_received' => count($processes),
            'jobs_importable' => count($vacancies),
        ]);

        return $vacancies;
    }

    private function fetchDescription(string $url): string
    {
        try {
            $response = Http::timeout(45)
                ->withOptions(['verify' => $this->caBundle ?: true])
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; TI-Vagas/1.0)',
                    'Accept' => 'text/html,application/xhtml+xml',
                ])
                ->get($url);

            $response->throw();

            return $this->extractPageText($response->body());
        } catch (Throwable $exception) {
            Log::warning('LEVTY: falha ao consultar página da oportunidade.', [
                'url' => $url,
                'error' => $exception->getMessage(),
            ]);

            return '';
        }
    }

    private function extractPageText(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument();
            $document->loadHTML('<?xml encoding="UTF-8">' . $html);
            $xpath = new DOMXPath($document);

            foreach ($xpath->query('//script|//style|//noscript|//nav|//header|//footer|//button') as $node) {
                $node->parentNode?->removeChild($node);
            }

            $main = $xpath->query('//main')->item(0)
                ?? $xpath->query('//body')->item(0)
                ?? $document->documentElement;

            $text = html_entity_decode(
                $main?->textContent ?? '',
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );

            $text = preg_replace('/[\x{00A0}\s]+/u', ' ', $text) ?? '';

            return trim($text);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function isTrainee(array $process, string $title): bool
    {
        $employmentType = mb_strtolower(trim((string) ($process['employmentType'] ?? '')));

        return str_contains(mb_strtolower($title), 'trainee')
            || str_contains(mb_strtolower($title), 'estagi')
            || in_array($employmentType, ['trainee', 'estágio', 'estagio', 'internship'], true);
    }

    private function normalizeEmploymentType(mixed $value): ?string
    {
        $value = $this->cleanText($value);

        return $value !== '' ? $value : null;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc()->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }

    private function cleanText(mixed $value): string
    {
        if (!is_string($value) && !is_numeric($value)) {
            return '';
        }

        $value = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }
}
