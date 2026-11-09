<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Services\VacancyLocationFilter;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WorkdaySource implements VacancySource
{
    private const PAGE_SIZE = 20;

    private readonly VacancyLocationFilter $locationFilter;

    public function __construct(
        private readonly string $host,
        private readonly string $tenant,
        private readonly string $site,
        private readonly string $companyName,
        private readonly string $locale = 'en-US',
        private readonly ?string $caBundle = null,
        private readonly int $maxJobs = 200,
        ?VacancyLocationFilter $locationFilter = null,
    ) {
        $this->locationFilter = $locationFilter
            ?? new VacancyLocationFilter();
    }

    public function name(): string
    {
        return 'workday-' . $this->tenant;
    }

    public function fetch(): iterable
    {
        $host = preg_replace(
            '~^https?://~i',
            '',
            rtrim($this->host, '/')
        );

        $endpoint = sprintf(
            'https://%s/wday/cxs/%s/%s/jobs',
            $host,
            $this->tenant,
            $this->site
        );

        $offset = 0;
        $totalAvailable = null;
        $processed = 0;
        $vacancies = [];

        while ($processed < $this->maxJobs) {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(60)
                ->withHeaders([
                    'User-Agent' => 'TI-Vagas/1.0',
                ])
                ->withOptions([
                    'verify' => $this->caBundle ?: true,
                ])
                ->post($endpoint, [
                    'appliedFacets' => (object) [],
                    'limit' => self::PAGE_SIZE,
                    'offset' => $offset,
                    'searchText' => '',
                ]);

            $response->throw();

            $payload = $response->json();

            if (!is_array($payload)) {
                throw new RuntimeException(
                    'Resposta inválida recebida do Workday.'
                );
            }

            $postings = $payload['jobPostings'] ?? [];

            if (!is_array($postings) || $postings === []) {
                break;
            }

            if ($totalAvailable === null) {
                $totalAvailable = max(
                    (int) ($payload['total'] ?? 0),
                    count($postings)
                );
            }

            foreach ($postings as $posting) {
                if ($processed >= $this->maxJobs) {
                    break;
                }

                $processed++;

                if (!is_array($posting)) {
                    continue;
                }

                $title = trim($posting['title'] ?? '');
                $externalPath = trim(
                    $posting['externalPath'] ?? ''
                );

                $location = trim(
                    $posting['locationsText'] ?? ''
                );

                if (
                    $title === '' ||
                    $externalPath === '' ||
                    !str_starts_with($externalPath, '/')
                ) {
                    continue;
                }

                $workModel = $this->locationFilter->classifyWorkModel(
                    $title,
                    $location,
                    ''
                );

                if (!$this->locationFilter->isEligibleForCollection(
                    $location,
                    $title,
                    '',
                    $workModel
                )) {
                    continue;
                }

                $detail = $this->fetchJobDetails(
                    $endpoint,
                    $externalPath
                );

                if ($detail === null) {
                    continue;
                }

                $description = $this->cleanDescription(
                    $detail['jobDescription'] ?? ''
                );

                $publishedAt = trim(
                    $detail['startDate'] ?? ''
                );

                $detailTitle = trim(
                    $detail['title'] ?? $title
                );

                $detailLocation = trim(
                    $detail['location'] ?? $location
                );

                $remoteType = strtolower(
                    trim($detail['remoteType'] ?? '')
                );

                if (str_contains($remoteType, 'remote')) {
                    $workModel = 'Remoto';
                } elseif (str_contains($remoteType, 'hybrid')) {
                    $workModel = 'Híbrido';
                } elseif (
                    str_contains($remoteType, 'onsite') ||
                    str_contains($remoteType, 'on-site')
                ) {
                    $workModel = 'Presencial';
                } else {
                    $workModel = $this->locationFilter->classifyWorkModel(
                        $detailTitle,
                        $detailLocation,
                        $description
                    );
                }

                if (
                    $description === '' ||
                    $publishedAt === '' ||
                    $detailTitle === ''
                ) {
                    continue;
                }

                if (!$this->locationFilter->isEligibleForCollection(
                    $detailLocation,
                    $detailTitle,
                    $description,
                    $workModel
                )) {
                    continue;
                }

                $applicationUrl = trim(
                    $detail['externalUrl'] ?? ''
                );

                if (!filter_var(
                    $applicationUrl,
                    FILTER_VALIDATE_URL
                )) {
                    $applicationUrl = sprintf(
                        'https://%s/%s/%s%s',
                        $host,
                        $this->locale,
                        $this->site,
                        $externalPath
                    );
                }

                $requisitionId = trim(
                    $detail['jobReqId'] ?? ''
                );

                $vacancies[] = [
                    'title' => $detailTitle,
                    'company' => $this->companyName,
                    'description' => $description,
                    'location' => $detailLocation ?: null,
                    'work_model' => $workModel,
                    'employment_type' => $this->normalizeEmploymentType(
                        $detail['timeType'] ?? ''
                    ),
                    'salary' => null,
                    'application_url' => $applicationUrl,
                    'external_id' => $requisitionId !== ''
                        ? $requisitionId
                        : $externalPath,
                    'original_published_at' => $publishedAt,
                ];
            }

            $offset += self::PAGE_SIZE;

            if (
                $offset >= $totalAvailable ||
                count($postings) < self::PAGE_SIZE
            ) {
                break;
            }
        }

        return $vacancies;
    }

    private function fetchJobDetails(
        string $endpoint,
        string $externalPath
    ): ?array {
        $url = preg_replace(
            '~/jobs$~',
            $externalPath,
            $endpoint
        );

        $response = Http::acceptJson()
            ->timeout(60)
            ->withHeaders([
                'User-Agent' => 'TI-Vagas/1.0',
            ])
            ->withOptions([
                'verify' => $this->caBundle ?: true,
            ])
            ->get($url);

        if (!$response->successful()) {
            return null;
        }

        $payload = $response->json('jobPostingInfo');

        return is_array($payload) ? $payload : null;
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

    private function normalizeEmploymentType(string $type): ?string
    {
        return match (strtolower(trim($type))) {
            'full_time' => 'Tempo integral',
            'part_time' => 'Meio período',
            'contract' => 'Contrato',
            'internship' => 'Estágio',
            default => trim($type) !== '' ? trim($type) : null,
        };
    }
}
