<?php

namespace Tests\Feature;

use App\Models\Vacancy;
use App\Models\VacancyAvailabilityCheck;
use App\Services\VacancyLinkChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VacancyLinkCheckerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_marks_a_successful_application_page_as_active_and_records_the_check(): void
    {
        Http::fake([
            'careers.example.com/*' => Http::response('<html><body>Candidate-se a esta oportunidade.</body></html>', 200),
        ]);

        $vacancy = $this->makeVacancy();

        $status = app(VacancyLinkChecker::class)->check($vacancy);

        $this->assertSame('active', $status);
        $this->assertDatabaseHas('vacancies', [
            'id' => $vacancy->id,
            'availability_status' => 'active',
            'availability_http_status' => 200,
        ]);
        $this->assertDatabaseHas('vacancy_availability_checks', [
            'vacancy_id' => $vacancy->id,
            'check_type' => 'http',
            'status' => 'active',
            'http_status' => 200,
        ]);
    }

    public function test_it_marks_a_first_not_found_response_as_suspect(): void
    {
        Http::fake([
            'careers.example.com/*' => Http::response('Not found', 404),
        ]);

        $vacancy = $this->makeVacancy();

        $status = app(VacancyLinkChecker::class)->check($vacancy);

        $this->assertSame('suspect', $status);
        $this->assertDatabaseHas('vacancies', [
            'id' => $vacancy->id,
            'availability_status' => 'suspect',
            'availability_consecutive_failures' => 1,
        ]);
    }

    public function test_it_marks_repeated_not_found_responses_as_expired(): void
    {
        Http::fake([
            'careers.example.com/*' => Http::response('Not found', 404),
        ]);

        $vacancy = $this->makeVacancy();

        $checker = app(VacancyLinkChecker::class);

        $this->assertSame('suspect', $checker->check($vacancy->fresh()));
        $this->assertSame('expired', $checker->check($vacancy->fresh()));

        $this->assertDatabaseHas('vacancies', [
            'id' => $vacancy->id,
            'availability_status' => 'expired',
            'availability_consecutive_failures' => 2,
        ]);
        $this->assertDatabaseCount('vacancy_availability_checks', 2);
    }

    public function test_it_marks_http_gone_as_expired(): void
    {
        Http::fake([
            'careers.example.com/*' => Http::response('Gone', 410),
        ]);

        $vacancy = $this->makeVacancy();

        $status = app(VacancyLinkChecker::class)->check($vacancy);

        $this->assertSame('expired', $status);
    }

    public function test_it_keeps_server_errors_as_suspect_instead_of_expiring_them(): void
    {
        Http::fake([
            'careers.example.com/*' => Http::response('Service unavailable', 503),
        ]);

        $vacancy = $this->makeVacancy();

        $status = app(VacancyLinkChecker::class)->check($vacancy);

        $this->assertSame('suspect', $status);
        $this->assertDatabaseHas('vacancies', [
            'id' => $vacancy->id,
            'availability_status' => 'suspect',
            'availability_http_status' => 503,
        ]);
    }

    public function test_it_marks_a_page_as_expired_when_its_content_says_the_vacancy_is_closed(): void
    {
        Http::fake([
            'careers.example.com/*' => Http::response(
                '<html><body>Esta vaga foi encerrada.</body></html>',
                200
            ),
        ]);

        $vacancy = $this->makeVacancy();

        $status = app(VacancyLinkChecker::class)->check($vacancy);

        $this->assertSame('expired', $status);
    }

    private function makeVacancy(): Vacancy
    {
        return Vacancy::query()->create([
            'title' => 'Desenvolvedor PHP Júnior',
            'company' => 'Empresa de Teste',
            'description' => 'Descrição da oportunidade.',
            'location' => 'Remoto - Brasil',
            'work_model' => 'Remoto',
            'employment_type' => 'CLT',
            'application_url' => 'https://careers.example.com/jobs/123',
            'source' => 'test-source',
            'external_id' => 'test-123',
            'original_published_at' => now()->subDay(),
            'collected_at' => now(),
        ]);
    }
}
