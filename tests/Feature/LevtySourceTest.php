<?php

namespace Tests\Feature;

use App\Services\VacancyLocationFilter;
use App\Services\VacancySourceFactory;
use App\Sources\LevtySource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LevtySourceTest extends TestCase
{
    private const API_URL = 'https://levty.sydle.one/api/1/site-institucional/com.levty.siteLEVTY/SiteAPI/provideSelectiveProcesses?includeTrainee=true';

    private const DETAIL_URL = 'https://www.levty.com/br/carreiras/desenvolvedor-belo-horizonte-mg';

    private function process(array $overrides = []): array
    {
        return array_merge([
            '_id' => '6ac50b4e174d730792b2eb18',
            'name' => 'Desenvolvedor(a)',
            'nameForUrl' => 'desenvolvedor-belo-horizonte-mg',
            'quantity' => 40,
            'city' => [
                'name' => 'Belo Horizonte',
                'state' => ['initials' => 'MG'],
            ],
            'employmentType' => null,
            'isRemote' => false,
            'publicationDate' => '2026-10-06T03:00:00Z',
            'pcdOnly' => false,
            'urls' => [
                [
                    'selectiveProcess' => '6ac50b4e174d730792b2eb181',
                    'nameForUrl' => 'desenvolvedor-belo-horizonte-mg',
                    'name' => 'Desenvolvedor(a)',
                ],
            ],
        ], $overrides);
    }

    private function makeSource(): LevtySource
    {
        return new LevtySource(
            caBundle: null,
            maxJobs: 500,
            locationFilter: app(VacancyLocationFilter::class),
        );
    }

    private function fakeApi(array $processes): void
    {
        Http::fake([
            self::API_URL => Http::response([
                'selectiveProcesses' => $processes,
            ], 200, ['Content-Type' => 'application/json']),
            self::DETAIL_URL => Http::response(
                '<!doctype html><html><body><main><h1>Desenvolvedor(a)</h1><p>Atuação no desenvolvimento e manutenção de aplicações web, APIs REST e integrações. Requisitos: experiência com desenvolvimento de software, lógica de programação e bancos de dados.</p></main></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=UTF-8']
            ),
        ]);
    }

    public function test_source_name_and_factory_registration(): void
    {
        config([
            'vacancy_sources.levty.ca_bundle' => null,
            'vacancy_sources.levty.max_jobs' => 500,
        ]);

        $this->assertSame('levty', $this->makeSource()->name());

        $source = app(VacancySourceFactory::class)->make('levty');

        $this->assertInstanceOf(LevtySource::class, $source);
    }

    public function test_fetch_normalizes_public_process_and_preserves_original_date(): void
    {
        $this->fakeApi([$this->process()]);

        $vacancies = array_values(iterator_to_array($this->makeSource()->fetch()));

        $this->assertCount(1, $vacancies);
        $this->assertSame('Desenvolvedor(a)', $vacancies[0]['title']);
        $this->assertSame('LEVTY', $vacancies[0]['company']);
        $this->assertSame('Belo Horizonte/MG', $vacancies[0]['location']);
        $this->assertSame(
            'https://www.levty.com/br/carreiras/desenvolvedor-belo-horizonte-mg',
            $vacancies[0]['application_url']
        );
        $this->assertSame(
            'levty:6ac50b4e174d730792b2eb18',
            $vacancies[0]['external_id']
        );
        $this->assertSame('2026-10-06 03:00:00', $vacancies[0]['original_published_at']);
        $this->assertNotEmpty($vacancies[0]['description']);
        $this->assertNull($vacancies[0]['work_model']);
    }

    public function test_fetch_skips_trainee_and_internship_processes(): void
    {
        $trainee = $this->process([
            '_id' => 'trainee-1',
            'name' => 'Trainee 2027',
            'nameForUrl' => 'trainee-2027-vicosa-mg',
            'employmentType' => 'Trainee',
        ]);

        $intern = $this->process([
            '_id' => 'intern-1',
            'name' => 'Estagiário(a) de Desenvolvimento',
            'nameForUrl' => 'estagiario-desenvolvimento',
        ]);

        $this->fakeApi([$trainee, $intern]);

        $vacancies = array_values(iterator_to_array($this->makeSource()->fetch()));

        $this->assertCount(0, $vacancies);

        Http::assertNotSent(function (Request $request) {
            return str_contains($request->url(), '/br/carreiras/');
        });
    }

    public function test_fetch_skips_process_without_original_publication_date(): void
    {
        $this->fakeApi([
            $this->process(['publicationDate' => null]),
        ]);

        $vacancies = array_values(iterator_to_array($this->makeSource()->fetch()));

        $this->assertCount(0, $vacancies);

        Http::assertNotSent(function (Request $request) {
            return str_contains($request->url(), '/br/carreiras/');
        });
    }

    public function test_fetch_deduplicates_process_ids(): void
    {
        $process = $this->process();
        $this->fakeApi([$process, $process]);

        $vacancies = array_values(iterator_to_array($this->makeSource()->fetch()));

        $this->assertCount(1, $vacancies);
    }

    public function test_fetch_ignores_process_when_detail_page_is_unavailable(): void
    {
        Http::fake([
            self::API_URL => Http::response([
                'selectiveProcesses' => [$this->process()],
            ], 200),
            self::DETAIL_URL => Http::response('Not found', 404),
        ]);

        $vacancies = array_values(iterator_to_array($this->makeSource()->fetch()));

        $this->assertCount(0, $vacancies);
    }

    public function test_fetch_throws_for_invalid_api_payload(): void
    {
        Http::fake([
            self::API_URL => Http::response(['unexpected' => []], 200),
        ]);

        $this->expectException(\RuntimeException::class);

        iterator_to_array($this->makeSource()->fetch());
    }
}
