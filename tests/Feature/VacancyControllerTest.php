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

    public function test_vacancies_requiring_three_or_more_years_do_not_appear_in_listing(): void
    {
        $experienceRequirements = [
            '5+ years in DevOps, SRE or cloud infrastructure.',
            '8 years of experience in infrastructure.',
            '10 years of experience in software development.',
        ];

        $vacancies = collect($experienceRequirements)
            ->map(function (string $description, int $index) {
                return $this->createVacancy([
                    'title' => 'DevOps Engineer Junior Test ' . ($index + 1),
                    'company' => 'Empresa Teste ' . ($index + 1),
                    'description' => $description,
                ]);
            });

        $response = $this->getAcceptedTerms()
            ->get(route('vacancies.index'));

        $response->assertOk();

        foreach ($vacancies as $vacancy) {
            $response->assertDontSee($vacancy->title);
            $response->assertDontSee($vacancy->company);
        }
    }

    public function test_vacancies_requiring_three_or_more_years_return_404_when_accessed_directly(): void
    {
        $experienceRequirements = [
            '5+ years in DevOps, SRE or cloud infrastructure.',
            '8 years of experience in infrastructure.',
            '10 years of experience in software development.',
        ];

        $vacancies = collect($experienceRequirements)
            ->map(function (string $description, int $index) {
                return $this->createVacancy([
                    'title' => 'DevOps Engineer Junior Test ' . ($index + 1),
                    'company' => 'Empresa Teste ' . ($index + 1),
                    'description' => $description,
                ]);
            });

        foreach ($vacancies as $vacancy) {
            $response = $this->getAcceptedTerms()
                ->get(route('vacancies.show', $vacancy));

            $response->assertNotFound();
        }
    }

    public function test_vacancy_without_junior_seniority_is_not_listed(): void
    {
        $vacancy = $this->createVacancy([
            'title' => 'Desenvolvedor PHP',
            'description' => 'Vaga para desenvolvimento de aplicações com PHP e Laravel.',
        ]);

        $response = $this->getAcceptedTerms()
            ->get(route('vacancies.index'));

        $response->assertOk();
        $response->assertDontSee($vacancy->title);
        $response->assertDontSee($vacancy->company);
    }

    public function test_internship_vacancy_is_not_listed(): void
    {
        $vacancy = $this->createVacancy([
            'title' => 'Estagiário de Desenvolvimento PHP',
            'description' => 'Oportunidade de estágio para desenvolvimento PHP.',
        ]);

        $response = $this->getAcceptedTerms()
            ->get(route('vacancies.index'));

        $response->assertOk();
        $response->assertDontSee($vacancy->title);
        $response->assertDontSee($vacancy->company);
    }

    public function test_internship_in_english_is_not_listed(): void
    {
        $vacancy = $this->createVacancy([
            'title' => 'Software Development Intern',
            'description' => 'Internship opportunity for software development.',
        ]);

        $response = $this->getAcceptedTerms()
            ->get(route('vacancies.index'));

        $response->assertOk();
        $response->assertDontSee($vacancy->title);
        $response->assertDontSee($vacancy->company);
    }

    public function test_trainee_vacancy_is_not_listed(): void
    {
        $vacancy = $this->createVacancy([
            'title' => 'Trainee de Tecnologia',
            'description' => 'Programa trainee para profissionais de tecnologia.',
        ]);

        $response = $this->getAcceptedTerms()
            ->get(route('vacancies.index'));

        $response->assertOk();
        $response->assertDontSee($vacancy->title);
        $response->assertDontSee($vacancy->company);
    }

    public function test_sap_abap_vacancy_is_not_listed(): void
    {
        $vacancy = $this->createVacancy([
            'title' => 'Desenvolvedor SAP ABAP Júnior',
            'description' => 'Desenvolvimento e manutenção de soluções SAP ABAP.',
        ]);

        $response = $this->getAcceptedTerms()
            ->get(route('vacancies.index'));

        $response->assertOk();
        $response->assertDontSee($vacancy->title);
        $response->assertDontSee($vacancy->company);
    }

    public function test_erp_specialist_vacancy_is_not_listed(): void
    {
        $vacancy = $this->createVacancy([
            'title' => 'Analista ERP Júnior',
            'description' => 'Atuação especializada em implantação e suporte ERP.',
        ]);

        $response = $this->getAcceptedTerms()
            ->get(route('vacancies.index'));

        $response->assertOk();
        $response->assertDontSee($vacancy->title);
        $response->assertDontSee($vacancy->company);
    }

    public function test_crm_specialist_vacancy_is_not_listed(): void
    {
        $vacancy = $this->createVacancy([
            'title' => 'Analista CRM Júnior',
            'description' => 'Administração e configuração de plataforma CRM.',
        ]);

        $response = $this->getAcceptedTerms()
            ->get(route('vacancies.index'));

        $response->assertOk();
        $response->assertDontSee($vacancy->title);
        $response->assertDontSee($vacancy->company);
    }

    public function test_data_science_and_bi_specialist_vacancies_are_not_listed(): void
    {
        $titles = [
            'Cientista de Dados Júnior',
            'Engenheiro de Dados Júnior',
            'Analista de BI Júnior',
            'Data Scientist Junior',
            'Data Engineer Junior',
        ];

        $vacancies = collect($titles)
            ->map(function (string $title, int $index) {
                return $this->createVacancy([
                    'title' => $title,
                    'company' => 'Empresa de Dados ' . $index,
                    'description' => 'Atuação profissional especializada na área de dados.',
                ]);
            });

        $response = $this->getAcceptedTerms()
            ->get(route('vacancies.index'));

        $response->assertOk();

        foreach ($vacancies as $vacancy) {
            $response->assertDontSee($vacancy->title);
            $response->assertDontSee($vacancy->company);
        }
    }

    public function test_machine_learning_specialist_vacancy_is_not_listed(): void
    {
        $vacancy = $this->createVacancy([
            'title' => 'Machine Learning Engineer Junior',
            'description' => 'Desenvolvimento de modelos de machine learning.',
        ]);

        $response = $this->getAcceptedTerms()
            ->get(route('vacancies.index'));

        $response->assertOk();
        $response->assertDontSee($vacancy->title);
        $response->assertDontSee($vacancy->company);
    }

    public function test_conventional_developer_vacancy_is_not_excluded_by_incidental_data_or_erp_mentions(): void
    {
        $vacancy = $this->createVacancy([
            'title' => 'Desenvolvedor Backend PHP Júnior',
            'description' => 'Desenvolvimento de APIs com Laravel e integração com sistemas ERP. Conhecimento em BI é considerado diferencial.',
        ]);

        $response = $this->getAcceptedTerms()
            ->get(route('vacancies.index'));

        $response->assertOk();
        $response->assertSee($vacancy->title);
        $response->assertSee($vacancy->company);
    }

    public function test_software_engineer_i_is_accepted_as_junior_equivalent(): void
    {
        $vacancy = $this->createVacancy([
            'title' => 'Software Engineer I',
            'description' => 'Desenvolvimento de software com Java.',
        ]);

        $response = $this->getAcceptedTerms()
            ->get(route('vacancies.index'));

        $response->assertOk();
        $response->assertSee($vacancy->title);
        $response->assertSee($vacancy->company);
    }
}
