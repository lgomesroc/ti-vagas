<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Services\VacancyLocationFilter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CsodSource implements VacancySource
{
    public function __construct(
        private readonly string $companyKey,
        private readonly string $companyName,
        private readonly string $careerSiteUrl,
        private readonly int $siteNumber = 1,
        private readonly int $maxJobs = 200,
        private readonly ?string $caBundle = null,
        private readonly ?VacancyLocationFilter $locationFilter = null,
    ) {
    }

    public function name(): string
    {
        return 'csod-' . $this->companyKey;
    }

    public function fetch(): iterable
    {
        $bootstrap = $this->bootstrap();

        if ($bootstrap === null) {
            throw new RuntimeException('Não foi possível obter o token público do portal CSOD.');
        }

        $vacancies = [];
        $seen = [];
        $pageSize = 25;
        $maxPages = (int) ceil(max(1, $this->maxJobs) / $pageSize);

        for ($page = 1; $page <= $maxPages && count($vacancies) < $this->maxJobs; $page++) {
            $response = $this->http()
                ->withToken($bootstrap['token'])
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Csod-Accept-Language' => $bootstrap['culture_name'],
                    'Referer' => $this->careerSiteUrl,
                ])
                ->post($bootstrap['cloud'] . '/rec-job-search/external/jobs', [
                    'careerSiteId' => $this->siteNumber,
                    'careerSitePageId' => $this->siteNumber,
                    'pageNumber' => $page,
                    'pageSize' => $pageSize,
                    'cultureId' => $bootstrap['culture_id'],
                    'searchText' => '',
                    'cultureName' => $bootstrap['culture_name'],
                    'states' => [],
                    'countryCodes' => [],
                    'cities' => [],
                    'placeID' => '',
                    'radius' => null,
                    'postingsWithinDays' => null,
                    'customFieldCheckboxKeys' => [],
                    'customFieldDropdowns' => [],
                    'customFieldRadios' => [],
                ]);

            $response->throw();
            $payload = $response->json();
            $rows = data_get($payload, 'data.requisitions', []);

            if (!is_array($rows)) {
                throw new RuntimeException('A resposta de vagas da CSOD possui formato inválido.');
            }

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $vacancy = $this->normalizeVacancy($row);

                if ($vacancy === null || isset($seen[$vacancy['external_id']])) {
                    continue;
                }

                $seen[$vacancy['external_id']] = true;
                $vacancies[] = $vacancy;

                if (count($vacancies) >= $this->maxJobs) {
                    break;
                }
            }

            $total = (int) data_get($payload, 'data.totalCount', 0);

            if ($total > 0 && $page * $pageSize >= $total) {
                break;
            }
        }

        Log::info('CSOD: coleta concluída.', [
            'company' => $this->companyName,
            'jobs_extracted' => count($vacancies),
        ]);

        return $vacancies;
    }

    private function bootstrap(): ?array
    {
        $response = $this->http()
            ->withHeaders(['Accept' => 'text/html,application/xhtml+xml'])
            ->get($this->careerSiteUrl);

        $response->throw();
        $html = $response->body();

        if (!preg_match('/"token"\s*:\s*"([^"]+)"/i', $html, $tokenMatch)) {
            return null;
        }

        if (!preg_match('/([a-z0-9-]+\.api\.csod\.com)/i', $html, $cloudMatch)) {
            return null;
        }

        $cloud = 'https://' . strtolower($cloudMatch[1]);

        $cultureId = 1;
        $cultureName = 'pt-BR';

        if (preg_match('/"cultureI[Dd]"\s*:\s*(\d+)/', $html, $cultureMatch)) {
            $cultureId = (int) $cultureMatch[1];
        }

        if (preg_match('/"cultureName"\s*:\s*"([^"]+)"/', $html, $cultureNameMatch)) {
            $cultureName = $cultureNameMatch[1];
        }

        return [
            'token' => html_entity_decode($tokenMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'cloud' => $cloud,
            'culture_id' => $cultureId,
            'culture_name' => $cultureName,
        ];
    }

    private function normalizeVacancy(array $row): ?array
    {
        $id = $row['requisitionId'] ?? $row['requisitionID'] ?? $row['id'] ?? null;
        $title = trim((string) ($row['displayJobTitle'] ?? $row['jobTitle'] ?? $row['title'] ?? ''));
        $description = $this->cleanDescription((string) ($row['externalDescription'] ?? ''));

        if ($id === null || $title === '' || $description === '') {
            return null;
        }

        $location = $this->extractLocation($row['locations'] ?? []);
        $filter = $this->locationFilter ?? new VacancyLocationFilter();
        $workModel = $filter->classifyWorkModel($title, $location, $description);

        if (!$filter->isEligibleForCollection($location, $title, $description, $workModel)) {
            return null;
        }

        $publishedAt = $this->normalizeDate(
            (string) ($row['postingEffectiveDate'] ?? $row['postedDate'] ?? '')
        );

        if ($publishedAt === null) {
            return null;
        }

        $applicationUrl = rtrim($this->careerSiteUrl, '/')
            . '/requisition/' . rawurlencode((string) $id)
            . '?c=' . rawurlencode($this->companyKey);

        return [
            'title' => $title,
            'company' => $this->companyName,
            'description' => $description,
            'location' => $location !== '' ? $location : 'Não informada',
            'work_model' => $workModel,
            'employment_type' => null,
            'salary' => null,
            'application_url' => $applicationUrl,
            'external_id' => (string) $id,
            'original_published_at' => $publishedAt,
        ];
    }

    private function extractLocation(mixed $locations): string
    {
        if (!is_array($locations)) {
            return '';
        }

        $labels = [];

        foreach ($locations as $location) {
            if (!is_array($location)) {
                continue;
            }

            $parts = array_filter([
                $this->scalar($location['city'] ?? null),
                $this->scalar($location['state'] ?? null),
                $this->scalar($location['country'] ?? null),
            ]);

            if ($parts !== []) {
                $labels[] = implode(', ', $parts);
            }
        }

        return implode(' / ', array_values(array_unique($labels)));
    }

    private function scalar(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value['name'] ?? $value['value'] ?? '';
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function normalizeDate(string $value): ?CarbonImmutable
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            foreach (['!d/m/Y', '!m/d/Y', DATE_ATOM] as $format) {
                $date = CarbonImmutable::createFromFormat($format, $value);

                if ($date !== false) {
                    return $date->utc();
                }
            }

            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function cleanDescription(string $description): string
    {
        $description = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $description = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', ' ', $description) ?? $description;
        $description = preg_replace('~</(p|div|li|br|h[1-6])\s*>~i', "\n", $description) ?? $description;
        $description = strip_tags($description);

        return trim(preg_replace('/[ \t]+/', ' ', preg_replace('/\n{3,}/', "\n\n", $description) ?? $description) ?? '');
    }

    private function http()
    {
        return Http::timeout(45)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; TI-Vagas/1.0)',
            ])
            ->withOptions([
                'verify' => $this->caBundle ?: true,
            ]);
    }
}
