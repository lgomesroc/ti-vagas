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

    public function test_it_deletes_an_existing_vacancy_when_it_becomes_unavailable(): void
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

        $this->assertDatabaseCount('vacancies', 0);
    }

    private function makeSource(array $vacancies): VacancySource
    {
        return new class($vacancies) implements VacancySource {
            public function __construct(
                private array $vacancies
            ) {
            }

            public function name(): string
            {
                return 'test-source';
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
