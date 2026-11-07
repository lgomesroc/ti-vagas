<?php

namespace Tests\Feature;

use App\Models\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VacancyControllerTest extends TestCase
{
    use RefreshDatabase;

    private function createVacancy(array $attributes = []): Vacancy
    {
        return Vacancy::create(array_merge([
            'title' => 'Desenvolvedor PHP Junior',
            'company' => 'Empresa de Testes',
            'description' => 'Vaga para desenvolvedor PHP junior com conhecimentos em Laravel.',
            'location' => 'Remoto - Brasil',
            'work_model' => 'Remoto',
            'employment_type' => 'CLT',
            'salary' => 'A combinar',
            'application_url' => 'https://example.com/candidatar',
            'source' => 'test',
            'external_id' => uniqid('vacancy-', true),
            'original_published_at' => now()->subDays(5),
            'collected_at' => now(),
        ], $attributes));
    }

    private function getAcceptedTerms()
    {
        return $this->withCookie(
            'ti_vagas_terms_accepted',
            '1.0'
        );
    }

    public function test_vacancy_listing_redirects_to_welcome_without_terms_acceptance(): void
    {
        $response = $this->get(route('vacancies.index'));

        $response->assertRedirect(route('welcome'));
    }

    public function test_vacancy_details_redirect_to_welcome_without_terms_acceptance(): void
    {
        $vacancy = $this->createVacancy();

        $response = $this->get(route('vacancies.show', $vacancy));

        $response->assertRedirect(route('welcome'));
    }

    public function test_unavailable_vacancy_does_not_appear_in_listing(): void
    {
        $vacancy = $this->createVacancy([
            'description' => 'Esta vaga foi encerrada e não aceita mais candidaturas.',
        ]);

        $response = $this->getAcceptedTerms()
            ->get(route('vacancies.index'));

        $response->assertOk();
        $response->assertDontSee($vacancy->title);
        $response->assertDontSee($vacancy->company);
    }

    public function test_unavailable_vacancy_returns_404_when_accessed_directly(): void
    {
        $vacancy = $this->createVacancy([
            'description' => 'Esta vaga foi encerrada e não aceita mais candidaturas.',
        ]);

        $response = $this->getAcceptedTerms()
            ->get(route('vacancies.show', $vacancy));

        $response->assertNotFound();
    }

    public function test_available_vacancy_appears_in_listing(): void
    {
        $vacancy = $this->createVacancy();

        $response = $this->getAcceptedTerms()
            ->get(route('vacancies.index'));

        $response->assertOk();
        $response->assertSee($vacancy->title);
        $response->assertSee($vacancy->company);
    }

    public function test_available_vacancy_can_be_accessed_directly(): void
    {
        $vacancy = $this->createVacancy();

        $response = $this->getAcceptedTerms()
            ->get(route('vacancies.show', $vacancy));

        $response->assertOk();
        $response->assertSee($vacancy->title);
        $response->assertSee($vacancy->company);
    }

    public function test_vacancy_older_than_60_days_does_not_appear_in_listing(): void
    {
        $vacancy = $this->createVacancy([
            'original_published_at' => now()->subDays(61),
        ]);

        $response = $this->getAcceptedTerms()
            ->get(route('vacancies.index'));

        $response->assertOk();
        $response->assertDontSee($vacancy->title);
        $response->assertDontSee($vacancy->company);
    }

    public function test_vacancy_older_than_60_days_returns_404_when_accessed_directly(): void
    {
        $vacancy = $this->createVacancy([
            'original_published_at' => now()->subDays(61),
        ]);

        $response = $this->getAcceptedTerms()
            ->get(route('vacancies.show', $vacancy));

        $response->assertNotFound();
    }
}
