<?php

namespace Tests\Feature;

use App\Services\VacancySourceFactory;
use App\Services\VacancyLocationFilter;
use App\Sources\BtgPactualSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BtgPactualSourceTest extends TestCase
{
    public function test_it_collects_btg_jobs_with_the_official_careers_application_url(): void
    {
        Http::fake([
            'boards-api.greenhouse.io/v1/boards/btgpactual/jobs*' => Http::response([
                'jobs' => [[
                    'id' => 6005484004,
                    'title' => 'Desenvolvedor Backend Júnior',
                    'first_published' => '2026-11-10T10:00:00Z',
                    'absolute_url' => 'https://boards.greenhouse.io/btgpactual/jobs/6005484004',
                    'location' => ['name' => 'São Paulo, SP'],
                    'content' => '<p>Desenvolvimento de aplicações backend.</p><ul><li>Java</li></ul>',
                ]],
            ]),
        ]);

        $source = new BtgPactualSource();
        $vacancies = collect($source->fetch())->all();

        $this->assertCount(1, $vacancies);
        $this->assertSame('BTG Pactual', $vacancies[0]['company']);
        $this->assertSame('6005484004', $vacancies[0]['external_id']);
        $this->assertSame(
            'https://carreiras.btgpactual.com/vagas?gh_jid=6005484004',
            $vacancies[0]['application_url']
        );
        $this->assertSame('greenhouse-btgpactual', $source->name());
    }

    public function test_it_ignores_incomplete_btg_job_records(): void
    {
        Http::fake([
            'boards-api.greenhouse.io/v1/boards/btgpactual/jobs*' => Http::response([
                'jobs' => [['id' => 123, 'title' => 'Desenvolvedor Júnior']],
            ]),
        ]);

        $source = new BtgPactualSource();

        $this->assertSame([], collect($source->fetch())->all());
    }

    public function test_factory_registers_btg_under_the_existing_source_identity(): void
    {
        config([
            'vacancy_sources.greenhouse.companies' => [],
        ]);

        $factory = app(VacancySourceFactory::class);
        $source = $factory->make('greenhouse-btgpactual');

        $this->assertInstanceOf(BtgPactualSource::class, $source);
    }
}
