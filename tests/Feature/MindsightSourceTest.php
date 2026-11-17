<?php

namespace Tests\Feature;

use App\Services\\VacancyLocationFilter;
use App\Sources\\MindsightSource;
use Illuminate\Support\\Facades\\Http;
use Mockery;
use Tests\TestCase;

class MindsightSourceTest extends TestCase
{
    private const BASE_URL = 'https://oportunidades.mindsight.com.br/guidance';

    private function makeSource(
        ?VacancyLocationFilter $locationFilter = null,
        int $maxJobs = 200,
    ): MindsightSource {
        return new MindsightSource(
            tenant: 'guidance',
            companyName: 'Guidance',
            baseUrl: self::BASE_URL,
            maxJobs: $maxJobs,
            locationFilter: $locationFilter ?? Mockery::mock(VacancyLocationFilter::class)
                ->shouldReceive('isEligibleForCollection')
                ->andReturn(true)
                ->getMock(),
        );
    }

    private function listingHtml(string $id = '123'): string
    {
        return '<html><body><a href="/guidance/' . $id . '/">'
            . 'Desenvolvedor PHP Júnior</a></body></html>';
    }

    private function detailHtml(array $overrides = []): string
    {
        $posting = array_merge([
            '@context' => 'https://schema.org',
            '@type' => 'JobPosting',
            'title' => 'Desenvolvedor PHP Júnior',
            'description' => '<p>Desenvolvimento de aplicações PHP.</p>'
                . '<p>Conhecimento em Laravel.</p>',
            'datePosted' => '2026-11-10',
            'employmentType' => 'FULL_TIME',
            'jobLocation' => [[
                'address' => [
                    'addressLocality' => 'Rio de Janeiro',
                    'addressRegion' => 'RJ',
                    'addressCountry' => 'Brazil',
                ],
            ]],
        ], $overrides);

        return '<html><head><script type="application/ld+json">'
            . json_encode($posting, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . '</script></head><body></body></html>';
    }

    public function test_source_name_uses_tenant(): void
    {
        $this->assertSame('mindsight-guidance', $this->makeSource()->name());
    }

    public function test_fetch_extracts_and_normalizes_job_posting_from_json_ld(): void
    {
        Http::fake([
            self::BASE_URL . '/' => Http::response($this->listingHtml()),
            self::BASE_URL . '/123/' => Http::response($this->detailHtml()),
        ]);

        $vacancies = $this->makeSource()->fetch();

        $this->assertCount(1, $vacancies);
        $this->assertSame('Desenvolvedor PHP Júnior', $vacancies[0]['title']);
        $this->assertSame('Guidance', $vacancies[0]['company']);
        $this->assertStringContainsString('Desenvolvimento de aplicações PHP.', $vacancies[0]['description']);
        $this->assertSame('Rio de Janeiro, RJ, Brazil', $vacancies[0]['location']);
        $this->assertSame('CLT', $vacancies[0]['employment_type']);
        $this->assertSame(self::BASE_URL . '/123/', $vacancies[0]['application_url']);
        $this->assertSame('123', $vacancies[0]['external_id']);
        $this->assertSame('2026-11-10T00:00:00+00:00', $vacancies[0]['original_published_at']);
    }

    public function test_fetch_uses_next_data_when_json_ld_is_not_available(): void
    {
        $nextData = [
            'props' => [
                'pageProps' => [
                    'job' => [
                        '@type' => 'JobPosting',
                        'title' => 'Analista de Suporte Júnior',
                        'description' => '<p>Atendimento e suporte técnico.</p>',
                        'datePosted' => '2026-11-11',
                        'employmentType' => 'FULL_TIME',
                    ],
                ],
            ],
        ];

        Http::fake([
            self::BASE_URL . '/' => Http::response($this->listingHtml()),
            self::BASE_URL . '/123/' => Http::response(
                '<script id="__NEXT_DATA__" type="application/json">'
                . json_encode($nextData, JSON_THROW_ON_ERROR)
                . '</script>'
            ),
        ]);

        $vacancies = $this->makeSource()->fetch();

        $this->assertCount(1, $vacancies);
        $this->assertSame('Analista de Suporte Júnior', $vacancies[0]['title']);
        $this->assertSame('123', $vacancies[0]['external_id']);
    }

    public function test_fetch_skips_vacancy_rejected_by_location_filter(): void
    {
        $filter = Mockery::mock(VacancyLocationFilter::class);
        $filter->shouldReceive('isEligibleForCollection')
            ->once()
            ->with(
                'Rio de Janeiro, RJ, Brazil',
                'Desenvolvedor PHP Júnior',
                Mockery::type('string'),
                null
            )
            ->andReturn(false);

        Http::fake([
            self::BASE_URL . '/' => Http::response($this->listingHtml()),
            self::BASE_URL . '/123/' => Http::response($this->detailHtml()),
        ]);

        $this->assertSame([], $this->makeSource($filter)->fetch());
    }

    public function test_fetch_respects_maximum_number_of_jobs(): void
    {
        Http::fake([
            self::BASE_URL . '/' => Http::response(
                $this->listingHtml('123') . $this->listingHtml('456')
            ),
            self::BASE_URL . '/123/' => Http::response($this->detailHtml()),
            self::BASE_URL . '/456/' => Http::response($this->detailHtml([
                'title' => 'Analista de Suporte Júnior',
            ])),
        ]);

        $vacancies = $this->makeSource(maxJobs: 1)->fetch();

        $this->assertCount(1, $vacancies);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/456/'));
    }

    public function test_fetch_ignores_detail_without_job_title(): void
    {
        Http::fake([
            self::BASE_URL . '/' => Http::response($this->listingHtml()),
            self::BASE_URL . '/123/' => Http::response(
                $this->detailHtml(['title' => ''])
            ),
        ]);

        $this->assertSame([], $this->makeSource()->fetch());
    }
}
