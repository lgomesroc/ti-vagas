<?php

namespace Tests\Feature;

use App\Services\VacancyLocationFilter;
use App\Sources\LinkedInJobsSource;
use Tests\TestCase;

class LinkedInJobsSourceTest extends TestCase
{
    public function test_source_does_not_reject_seniority_or_area_before_central_importer(): void
    {
        $source = new LinkedInJobsSource(app(VacancyLocationFilter::class));

        $vacancy = [
            'title' => 'Analista de Suporte Pleno',
            'company' => 'Acme Tecnologia',
            'description' => str_repeat('Atendimento e suporte a sistemas corporativos. ', 3),
            'location' => 'São Paulo, SP',
            'work_model' => 'Presencial',
            'application_url' => 'https://www.linkedin.com/jobs/view/123456/',
            'external_id' => 'linkedin-jobs:123456',
            'original_published_at' => now()->toIso8601String(),
        ];

        $this->assertCount(1, $source->filterFetched([$vacancy]));
    }

    public function test_source_still_discards_payloads_missing_fields_required_by_importer(): void
    {
        $source = new LinkedInJobsSource(app(VacancyLocationFilter::class));

        $this->assertSame([], $source->filterFetched([
            [
                'title' => 'Desenvolvedor PHP Júnior',
                'company' => 'Acme Tecnologia',
                'description' => 'Descrição sem link e sem identificador.',
            ],
        ]));
    }
}
