<?php

namespace Tests\\Feature;

use App\\Models\\Vacancy;
use App\\Services\\VacancyImporter;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Tests\\TestCase;

class VacancyEmailApplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_a_vacancy_with_corporate_email_and_source_post_without_application_url(): void
    {
        $postUrl = 'https://www.linkedin.com/feed/update/urn:li:activity:7530395139467337728/';
        $result = app(VacancyImporter::class)->importFetched('linkedin-posts', [[
            'title' => 'Desenvolvedor PHP Júnior',
            'company' => 'B4X',
            'description' => 'Desenvolvimento backend com PHP e Laravel. Não exigimos anos de experiência profissional.',
            'location' => 'Curitiba/PR',
            'work_model' => null,
            'employment_type' => null,
            'application_url' => null,
            'application_email' => 'junior@b4x.com.br',
            'application_subject' => 'Vaga Backend Júnior — PHP',
            'source_url' => $postUrl,
            'external_id' => 'urn:li:activity:7530395139467337728',
            'original_published_at' => now()->subDays(6)->toIso8601String(),
        ]]);

        $this->assertSame(['created' => 1, 'updated' => 0, 'skipped' => 0], $result);
        $this->assertDatabaseHas('vacancies', [
            'source' => 'linkedin-posts',
            'external_id' => 'urn:li:activity:7530395139467337728',
            'company' => 'B4X',
            'application_url' => null,
            'application_email' => 'junior@b4x.com.br',
            'application_subject' => 'Vaga Backend Júnior — PHP',
            'source_url' => $postUrl,
            'work_model' => null,
            'employment_type' => null,
        ]);
    }

    public function test_it_rejects_a_vacancy_without_application_url_or_email(): void
    {
        $result = app(VacancyImporter::class)->importFetched('linkedin-posts', [[
            'title' => 'Desenvolvedor PHP Júnior',
            'company' => 'B4X',
            'description' => 'Desenvolvimento backend com PHP e Laravel.',
            'location' => 'Curitiba/PR',
            'application_url' => null,
            'application_email' => null,
            'source_url' => 'https://www.linkedin.com/feed/update/urn:li:activity:1002/',
            'external_id' => 'urn:li:activity:1002',
            'original_published_at' => now()->subDay()->toIso8601String(),
        ]]);

        $this->assertSame(['created' => 0, 'updated' => 0, 'skipped' => 1], $result);
        $this->assertDatabaseMissing('vacancies', ['external_id' => 'urn:li:activity:1002']);
    }

    public function test_vacancy_details_render_email_subject_and_original_post(): void
    {
        $vacancy = Vacancy::query()->create([
            'title' => 'Desenvolvedor PHP Júnior',
            'company' => 'B4X',
            'description' => 'Desenvolvimento backend com PHP e Laravel.',
            'location' => 'Curitiba/PR',
            'work_model' => null,
            'employment_type' => null,
            'application_url' => null,
            'application_email' => 'junior@b4x.com.br',
            'application_subject' => 'Vaga Backend Júnior — PHP',
            'source_url' => 'https://www.linkedin.com/feed/update/urn:li:activity:7530395139467337728/',
            'source' => 'linkedin-posts',
            'external_id' => 'urn:li:activity:7530395139467337728',
            'original_published_at' => now()->subDays(6),
            'collected_at' => now(),
        ]);

        $html = view('vacancies.show', ['vacancy' => $vacancy])->render();

        $this->assertStringContainsString('mailto:junior@b4x.com.br?subject=', $html);
        $this->assertStringContainsString('Vaga Backend Júnior', $html);
        $this->assertStringContainsString('Ver publicação original', $html);
        $this->assertStringNotContainsString('Candidatar-se na empresa', $html);
    }
}
