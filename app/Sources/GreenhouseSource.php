<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Services\VacancyLocationFilter;
use Illuminate\Support\Facades\Http;

class GreenhouseSource implements VacancySource
{
    private const API_URL = 'https://boards-api.greenhouse.io/v1/boards';

    private readonly VacancyLocationFilter $locationFilter;

    public function __construct(
        private readonly string $boardToken,
        private readonly string $companyName,
        private readonly ?string $caBundle = null,
        ?VacancyLocationFilter $locationFilter = null,
    ) {
        $this->locationFilter = $locationFilter ?? new VacancyLocationFilter();
    }

    public function name(): string
    {
        return 'greenhouse-' . $this->boardToken;
    }

    public function fetch(): iterable
    {
        $response = Http::acceptJson()
            ->timeout(60)
            ->withOptions([
                'verify' => $this->caBundle ?: true,
            ])
            ->get(
                self::API_URL . '/' . $this->boardToken . '/jobs',
                ['content' => 'true']
            );

        $response->throw();

        $jobs = $response->json('jobs', []);
        $vacancies = [];

        foreach ($jobs as $job) {
            $jobId = $job['id'] ?? null;
            $publishedAt = $job['first_published'] ?? null;
            $applicationUrl = $job['absolute_url'] ?? null;
            $title = $job['title'] ?? null;
            $content = $job['content'] ?? null;

            if (
                !$jobId ||
                !$publishedAt ||
                !$applicationUrl ||
                !$title ||
                !$content
            ) {
                continue;
            }

            $location = $job['location']['name'] ?? null;

            // O painel Greenhouse da Stone é destinado às vagas brasileiras.
            // A API informa apenas "Remoto", sem identificar o país.
            // A normalização é exclusiva dessa fonte e não altera
            // os critérios de localização das demais empresas.
            if (
                $this->boardToken === 'stone'
                && in_array(
                    mb_strtolower(trim($location ?? '')),
                    ['remoto', 'remote'],
                    true
                )
            ) {
                $location = 'Remoto, Brasil';
            }

            $description = $this->cleanDescription($content);

            $workModel = $this->locationFilter->classifyWorkModel(
                $title,
                $location,
                $description
            );

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
                'company' => $this->companyName,
                'description' => $description,
                'location' => $location,
                'work_model' => $workModel,
                'employment_type' => null,
                'salary' => null,
                'application_url' => $applicationUrl,
                'external_id' => (string) $jobId,
                'original_published_at' => $publishedAt,
            ];
        }

        return $vacancies;
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
}
