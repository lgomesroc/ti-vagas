<?php

namespace App\Sources;

use App\Contracts\VacancySource;
use App\Services\VacancyLocationFilter;
use Illuminate\Support\Facades\Http;

class BtgPactualSource implements VacancySource
{
    private const API_URL = 'https://boards-api.greenhouse.io/v1/boards/btgpactual/jobs';

    private readonly VacancyLocationFilter $locationFilter;

    public function __construct(
        private readonly ?string $caBundle = null,
        ?VacancyLocationFilter $locationFilter = null,
    ) {
        $this->locationFilter = $locationFilter ?? new VacancyLocationFilter();
    }

    public function name(): string
    {
        return 'greenhouse-btgpactual';
    }

    public function fetch(): iterable
    {
        $response = Http::acceptJson()
            ->timeout(60)
            ->withOptions(['verify' => $this->caBundle ?: true])
            ->get(self::API_URL, ['content' => 'true']);

        $response->throw();

        $vacancies = [];

        foreach ($response->json('jobs', []) as $job) {
            $id = $job['id'] ?? null;
            $title = $job['title'] ?? null;
            $publishedAt = $job['first_published'] ?? null;
            $content = $job['content'] ?? null;

            if (!$id || !$title || !$publishedAt || !$content) {
                continue;
            }

            $location = $job['location']['name'] ?? null;
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
                'company' => 'BTG Pactual',
                'description' => $description,
                'location' => $location,
                'work_model' => $workModel,
                'employment_type' => null,
                'salary' => null,
                'application_url' => 'https://carreiras.btgpactual.com/vagas?gh_jid=' . rawurlencode((string) $id),
                'external_id' => (string) $id,
                'original_published_at' => $publishedAt,
            ];
        }

        return $vacancies;
    }

    private function cleanDescription(string $content): string
    {
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $content = preg_replace(
            '/<\\s*br\\s*\\/?>|<\\/\\s*p\\s*>|<\\/\\s*div\\s*>|<\\/\\s*h[1-6]\\s*>/i',
            "\\n",
            $content
        );
        $content = preg_replace('/<\\s*li\\b[^>]*>/i', "\\n- ", $content);
        $content = strip_tags($content);
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $content = preg_replace('/[ \\t]+/', ' ', $content);
        $content = preg_replace('/ *\\n */', "\\n", $content);
        $content = preg_replace('/\\n{3,}/', "\\n\\n", $content);

        return trim($content);
    }
}
