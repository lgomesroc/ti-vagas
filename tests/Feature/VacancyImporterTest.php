<?php

namespace Tests\Feature;

use App\Contracts\VacancySource;
use App\Models\Vacancy;
use App\Services\VacancyImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VacancyImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_new_vacancy(): void
    {
        $source = $this->makeSource([
            $this->validVacancy(),
        ]);

        $result = app(VacancyImporter::class)->import($source);

        $this->assertSame([
            'created' => 1,
            'updated' => 0,
            'skipped' => 0,
        ], $result);

        $this->assertDatabaseHas('vacancies', [
            'source' => 'test-source',
            'external_id' => 'vacancy-001',
            'title' => 'Desenvolvedor PHP Júnior',
        ]);
    }

    public function test_it_saves_valid_geographic_metadata(): void
    {
        $vacancy = $this->validVacancy([
            'city' => 'Niterói',
            'state' => 'RJ',
            'country' => 'Brasil',
            'ibge_municipality_code' => '3303302',
            'latitude' => -22.8832,
            'longitude' => -43.1034,
        ]);

        $source = $this->makeSource([$vacancy]);

        app(VacancyImporter::class)->import($source);

        $this->assertDatabaseHas('vacancies', [
            'source' => 'test-source',
            'external_id' => 'vacancy-001',
            'city' => 'Niterói',
            'state' => 'Rio de Janeiro',
            'state_code' => 'RJ',
            'country_code' => 'BR',
            'ibge_municipality_code' => '3303302',
            'latitude' => -22.8832,
            'longitude' => -43.1034,
        ]);
    }

    public function test_it_does_not_save_invalid_geographic_metadata(): void
    {
        $vacancy = $this->validVacancy([
            'city' => 'Niterói',
            'state' => 'RJ',
            'country' => 'Brasil',
            'ibge_municipality_code' => '123456',
            'latitude' => 91,
            'longitude' => -181,
        ]);

        $source = $this->makeSource([$vacancy]);

        app(VacancyImporter::class)->import($source);

        $this->assertDatabaseHas('vacancies', [
            'source' => 'test-source',
            'external_id' => 'vacancy-001',
            'city' => 'Niterói',
            'state' => 'Rio de Janeiro',
            'state_code' => 'RJ',
            'country_code' => 'BR',
            'ibge_municipality_code' => null,
            'latitude' => null,
            'longitude' => null,
        ]);
    }

    public function test_it_preserves_existing_geographic_metadata_when_source_does_not_send_it(): void
    {
        $initialVacancy = $this->validVacancy([
            'city' => 'Niterói',
            'state' => 'RJ',
            'country' => 'Brasil',
            'ibge_municipality_code' => '3303302',
            'latitude' => -22.8832,
            'longitude' => -43.1034,
        ]);

        $source = $this->makeSource([$initialVacancy]);

        app(VacancyImporter::class)->import($source);

        $updatedVacancy = $this->validVacancy([
            'title' => 'Desenvolvedor PHP Júnior - Atualizado',
        ]);

        $source = $this->makeSource([$updatedVacancy]);

        app(VacancyImporter::class)->import($source);

        $this->assertDatabaseHas('vacancies', [
            'source' => 'test-source',
            'external_id' => 'vacancy-001',
            'title' => 'Desenvolvedor PHP Júnior - Atualizado',
            'city' => 'Niterói',
            'state' => 'Rio de Janeiro',
            'state_code' => 'RJ',
            'country_code' => 'BR',
            'ibge_municipality_code' => '3303302',
            'latitude' => -22.8832,
            'longitude' => -43.1034,
        ]);
    }

    public function test_it_updates_an_existing_vacancy_without_changing_original_publication_date(): void
    {
        $initialVacancy = $this->validVacancy([
            'original_published_at' => '2026-10-01 09:30:00',
        ]);

        $source = $this->makeSource([$initialVacancy]);

        app(VacancyImporter::class)->import($source);

        $updatedVacancy = $this->validVacancy([
            'title' => 'Desenvolvedor PHP Júnior - Atualizado',
            'description' => 'Descrição atualizada da oportunidade.',
            'original_published_at' => '2026-10-10 15:00:00',
        ]);

        $source = $this->makeSource([$updatedVacancy]);

        $result = app(VacancyImporter::class)->import($source);

        $this->assertSame([
            'created' => 0,
            'updated' => 1,
            'skipped' => 0,
        ], $result);

        $vacancy = Vacancy::where('source', 'test-source')
            ->where('external_id', 'vacancy-001')
            ->firstOrFail();

        $this->assertSame(
            'Desenvolvedor PHP Júnior - Atualizado',
            $vacancy->title
        );

        $this->assertSame(
            '2026-10-01 09:30:00',
            $vacancy->original_published_at->format('Y-m-d H:i:s')
        );
    }

    public function test_linkedin_jobs_are_subject_to_the_editorial_filter(): void
    {
        $vacancy = $this->validVacancy([
            'title' => 'Analista de Marketing Sênior',
            'external_id' => 'linkedin-jobs:987654',
            'description' => 'Oportunidade encontrada na pesquisa do LinkedIn e preservada para revisão editorial.',
            'application_url' => 'https://www.linkedin.com/jobs/view/987654/',
        ]);

        $result = app(VacancyImporter::class)->import(
            $this->makeSource([$vacancy], 'linkedin-jobs')
        );

        $this->assertSame([
            'created' => 0,
            'updated' => 0,
            'skipped' => 1,
        ], $result);

        $this->assertDatabaseMissing('vacancies', [
            'source' => 'linkedin-jobs',
            'external_id' => 'linkedin-jobs:987654',
        ]);
    }

    public function test_global_gupy_import_updates_legacy_tenant_record_without_changing_its_source(): void
    {
        $legacy = $this->validVacancy([
            'external_id' => 'gupy-job-987',
            'application_url' => 'https://empresa.gupy.io/jobs/987',
        ]);

        app(VacancyImporter::class)->import(
            $this->makeSource([$legacy], 'gupy-empresa')
        );

        $updated = $this->validVacancy([
            'external_id' => 'gupy-job-987',
            'title' => 'Desenvolvedor PHP Júnior - Atualizado',
            'application_url' => 'https://empresa.gupy.io/jobs/987',
            'description' => 'Descrição atualizada da oportunidade.',
        ]);

        $result = app(VacancyImporter::class)->import(
            $this->makeSource([$updated], 'gupy-global')
        );

        $this->assertSame([
            'created' => 0,
            'updated' => 1,
            'skipped' => 0,
        ], $result);

        $this->assertDatabaseCount('vacancies', 1);
        $this->assertDatabaseHas('vacancies', [
            'source' => 'gupy-empresa',
            'external_id' => 'gupy-job-987',
            'title' => 'Desenvolvedor PHP Júnior - Atualizado',
        ]);
    }

    public function test_it_skips_vacancies_without_original_publication_date(): void
    {
        $vacancy = $this->validVacancy([
            'original_published_at' => null,
        ]);

        $source = $this->makeSource([$vacancy]);

        $result = app(VacancyImporter::class)->import($source);

        $this->assertSame([
            'created' => 0,
            'updated' => 0,
            'skipped' => 1,
        ], $result);

        $this->assertDatabaseCount('vacancies', 0);
    }

    public function test_it_skips_vacancies_without_external_id(): void
    {
        $vacancy = $this->validVacancy([
            'external_id' => null,
        ]);

        $source = $this->makeSource([$vacancy]);

        $result = app(VacancyImporter::class)->import($source);

        $this->assertSame([
            'created' => 0,
            'updated' => 0,
            'skipped' => 1,
        ], $result);

        $this->assertDatabaseCount('vacancies', 0);
    }

    public function test_it_does_not_create_an_unavailable_vacancy(): void
    {
        $vacancy = $this->validVacancy([
            'description' => 'Esta vaga foi encerrada.',
        ]);

        $source = $this->makeSource([$vacancy]);

        $result = app(VacancyImporter::class)->import($source);

        $this->assertSame([
            'created' => 0,
            'updated' => 0,
            'skipped' => 1,
        ], $result);

        $this->assertDatabaseCount('vacancies', 0);
    }

    public function test_it_preserves_an_existing_vacancy_and_records_when_it_becomes_unavailable(): void
    {
        $source = $this->makeSource([
            $this->validVacancy(),
        ]);

        app(VacancyImporter::class)->import($source);

        $this->assertDatabaseCount('vacancies', 1);

        $unavailableVacancy = $this->validVacancy([
            'description' => 'Esta vaga foi encerrada.',
        ]);

        $source = $this->makeSource([$unavailableVacancy]);

        $result = app(VacancyImporter::class)->import($source);

        $this->assertSame([
            'created' => 0,
            'updated' => 0,
            'skipped' => 1,
        ], $result);

        $this->assertDatabaseCount('vacancies', 1);
        $this->assertDatabaseHas('vacancies', [
            'source' => 'test-source',
            'external_id' => 'vacancy-001',
            'availability_status' => 'expired',
        ]);
        $this->assertDatabaseHas('vacancy_availability_checks', [
            'check_type' => 'source',
            'status' => 'expired',
            'reason' => 'A fonte informou que a oportunidade está indisponível.',
        ]);
    }

    private function makeSource(array $vacancies, string $sourceName = 'test-source'): VacancySource
    {
        return new class($vacancies, $sourceName) implements VacancySource {
            public function __construct(
                private array $vacancies,
                private string $sourceName
            ) {
            }

            public function name(): string
            {
                return $this->sourceName;
            }

            public function fetch(): iterable
            {
                return $this->vacancies;
            }
        };
    }

    private function validVacancy(array $overrides = []): array
    {
        return array_replace([
            'title' => 'Desenvolvedor PHP Júnior',
            'company' => 'Empresa Teste',
            'description' => 'Descrição da oportunidade de teste.',
            'location' => 'Brasil',
            'work_model' => 'Remoto',
            'employment_type' => 'CLT',
            'salary' => 'R$ 3.000 a R$ 4.000',
            'application_url' => 'https://example.com/candidatura',
            'external_id' => 'vacancy-001',
            'original_published_at' => '2026-10-01 09:30:00',
        ], $overrides);
    }
}
