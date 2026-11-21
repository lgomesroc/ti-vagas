<?php

namespace Tests\Feature;

use App\Sources\GupyGlobalSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GupyGlobalSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-21 12:00:00');
        Cache::forget(GupyGlobalSource::LAST_SUCCESSFUL_AT_KEY);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_maps_public_api_results_without_requesting_each_job_page(): void
    {
        Http::fake([
            'portal.gupy.io/api/job-search/jobs*' => Http::response(['data' => [[
                'id' => 987654,
                'name' => 'Desenvolvedor PHP Júnior',
                'careerPageName' => 'Empresa Teste',
                'careerPageSlug' => 'empresa-teste',
                'description' => '<p>Atuação com PHP e Laravel.</p>',
                'publishedDate' => '2026-11-20T10:00:00Z',
                'jobUrl' => 'https://empresa-teste.gupy.io/jobs/987654?jobBoardSource=gupy_public_page',
                'workplaceType' => 'remote',
                'type' => 'vacancy_type_effective',
                'city' => 'Rio de Janeiro',
                'state' => 'RJ',
            ]]]),
        ]);

        $source = new GupyGlobalSource(terms: ['desenvolvedor'], pageSize: 50, maxPages: 5);
        $jobs = $source->fetch();

        $this->assertCount(1, $jobs);
        $job = $jobs[0];
        $this->assertSame('987654', $job['external_id']);
        $this->assertSame('Empresa Teste', $job['company']);
        $this->assertSame('Desenvolvedor PHP Júnior', $job['title']);
        $this->assertSame('https://empresa-teste.gupy.io/jobs/987654', $job['application_url']);
        $this->assertSame('2026-11-20T10:00:00+00:00', $job['original_published_at']);
        $this->assertSame('Remoto', $job['work_model']);
        $this->assertSame('CLT', $job['employment_type']);

        Http::assertSent(fn ($request) =>
            str_starts_with($request->url(), 'https://portal.gupy.io/api/job-search/jobs?')
            && $request['jobName'] === 'desenvolvedor'
            && (int) $request['limit'] === 50
            && (int) $request['offset'] === 0
            && $request['sortBy'] === 'publishedDate'
            && $request['sortOrder'] === 'desc'
        );
        Http::assertSentCount(1);
    }

    public function test_it_stops_when_results_are_older_than_the_last_successful_collection(): void
    {
        Cache::put(
            GupyGlobalSource::LAST_SUCCESSFUL_AT_KEY,
            '2026-11-21T11:00:00+00:00',
            now()->addDays(90)
        );
        Http::fake([
            'portal.gupy.io/api/job-search/jobs*' => Http::response(['data' => [[
                'id' => 123,
                'name' => 'Desenvolvedor PHP Júnior',
                'careerPageName' => 'Empresa Teste',
                'description' => 'Descrição da vaga.',
                'publishedDate' => '2026-11-21T09:00:00Z',
                'jobUrl' => 'https://empresa.gupy.io/jobs/123',
                'workplaceType' => 'on-site',
                'type' => 'vacancy_type_effective',
                'city' => 'Niterói',
                'state' => 'RJ',
            ]]]),
        ]);

        $source = new GupyGlobalSource(terms: ['desenvolvedor'], pageSize: 50, maxPages: 5);
        $this->assertSame([], $source->fetch());

        Http::assertSentCount(1);
        $this->assertSame(1, $source->diagnostics()['outside_lookback']);
    }
}
