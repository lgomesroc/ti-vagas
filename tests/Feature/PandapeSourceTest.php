<?php

namespace Tests\Feature;

use App\Services\VacancyLocationFilter;
use App\Sources\PandapeSource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PandapeSourceTest extends TestCase
{
    private const BASE_URL = 'https://fiesc.pandape.infojobs.com.br/';

    private function makeSource(
        ?VacancyLocationFilter $locationFilter = null,
        int $maxPages = 100,
        ?string $caBundle = null
    ): PandapeSource {
        return new PandapeSource(
            companyKey: 'fiesc',
            companyName: 'FIESC',
            baseUrl: self::BASE_URL,
            companyId: 696,
            caBundle: $caBundle,
            maxPages: $maxPages,
            locationFilter: $locationFilter
                ?? Mockery::mock(VacancyLocationFilter::class)
                    ->shouldReceive('isEligibleForCollection')
                    ->andReturn(true)
                    ->getMock(),
        );
    }

    private function vacancyHtml(
        string $id,
        string $title = 'Técnico de Suporte de TI - Júnior',
        string $workModel = 'Presencial'
    ): string {
        return <<<HTML
        <div>
            <a class="card card-vacancy" href="/Detail/{$id}">
                <span>{$title}</span>
                <span>Rio do Sul - SC</span>
                <span>{$workModel}</span>
            </a>
        </div>
        HTML;
    }

    private function detailHtml(
        string $title = 'Técnico de Suporte de TI - Júnior',
        string $datePosted = '2026-11-04T09:51:01.6938001',
        string $description = 'Experiência com suporte técnico. Salário: R$ 3.135,87.'
    ): string {
        $job = [
            '@context' => 'https://schema.org',
            '@type' => 'JobPosting',
            'title' => $title,
            'description' => $description,
            'datePosted' => $datePosted,
            'employmentType' => 'full-time',
            'jobLocation' => [
                '@type' => 'Place',
                'address' => [
                    '@type' => 'PostalAddress',
                    'addressLocality' => 'Rio do Sul',
                    'addressRegion' => 'SC',
                    'addressCountry' => [
                        '@type' => 'Country',
                        'name' => 'Brazil',
                    ],
                ],
            ],
        ];

        return '<html><head><script type="application/ld+json">'
            . json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . '</script></head><body></body></html>';
    }

    private function mockHomeAndPage(
        string $html,
        bool $isLast = true
    ): void {
        Http::fake([
            self::BASE_URL => Http::response(
                '<html><body>FIESC</body></html>',
                200
            ),

            self::BASE_URL . 'ListVacancies' => Http::response([
                'view' => $html,
                'isLast' => $isLast,
                'subtotal' => 1,
            ], 200),
        ]);
    }

    public function test_source_name_uses_company_key(): void
    {
        $source = $this->makeSource();

        $this->assertSame('pandape:fiesc', $source->name());
    }

    public function test_fetch_returns_normalized_vacancy(): void
    {
        $this->mockHomeAndPage($this->vacancyHtml('3895067'));

        Http::fake([
            self::BASE_URL . 'Detail/3895067' => Http::response(
                $this->detailHtml(),
                200
            ),
        ]);

        $source = $this->makeSource();

        $vacancies = $source->fetch();

        $this->assertCount(1, $vacancies);

        $vacancy = $vacancies[0];

        $this->assertSame(
            'Técnico de Suporte de TI - Júnior',
            $vacancy['title']
        );

        $this->assertSame('FIESC', $vacancy['company']);

        $this->assertStringContainsString(
            'Experiência com suporte técnico',
            $vacancy['description']
        );

        $this->assertSame('Rio do Sul, SC, Brazil', $vacancy['location']);
        $this->assertSame('Presencial', $vacancy['work_model']);
        $this->assertSame('Tempo integral', $vacancy['employment_type']);
        $this->assertSame('R$ 3.135,87', $vacancy['salary']);

        $this->assertSame(
            self::BASE_URL . 'Detail/3895067',
            $vacancy['application_url']
        );

        $this->assertSame(
            'pandape:fiesc:3895067',
            $vacancy['external_id']
        );

        $this->assertSame('Rio do Sul', $vacancy['city']);
        $this->assertSame('SC', $vacancy['state']);
        $this->assertSame('Brazil', $vacancy['country']);

        $this->assertNull($vacancy['state_code']);
        $this->assertNull($vacancy['country_code']);
        $this->assertNull($vacancy['latitude']);
        $this->assertNull($vacancy['longitude']);

        $this->assertSame(
            '2026-11-04T09:51:01+00:00',
            $vacancy['original_published_at']
        );
    }

    public function test_fetch_processes_multiple_pages(): void
    {
        Http::fake(function (Request $request) {
            if (
                $request->method() === 'GET'
                && $request->url() === self::BASE_URL
            ) {
                return Http::response('<html>FIESC</html>', 200);
            }

            if ($request->url() === self::BASE_URL . 'ListVacancies') {
                $page = (int) $request['PageNumber'];

                if ($page === 1) {
                    return Http::response([
                        'view' => $this->vacancyHtml('100'),
                        'isLast' => false,
                        'subtotal' => 2,
                    ], 200);
                }

                if ($page === 2) {
                    return Http::response([
                        'view' => $this->vacancyHtml('200'),
                        'isLast' => true,
                        'subtotal' => 2,
                    ], 200);
                }
            }

            if ($request->url() === self::BASE_URL . 'Detail/100') {
                return Http::response($this->detailHtml(), 200);
            }

            if ($request->url() === self::BASE_URL . 'Detail/200') {
                return Http::response(
                    $this->detailHtml('Analista de Suporte de TI - Júnior'),
                    200
                );
            }

            return Http::response('Not found', 404);
        });

        $vacancies = $this->makeSource()->fetch();

        $this->assertCount(2, $vacancies);

        Http::assertSent(function (Request $request) {
            return $request->url() === self::BASE_URL . 'ListVacancies'
                && (int) $request['PageNumber'] === 1;
        });

        Http::assertSent(function (Request $request) {
            return $request->url() === self::BASE_URL . 'ListVacancies'
                && (int) $request['PageNumber'] === 2;
        });
    }

    public function test_fetch_does_not_process_duplicate_vacancy_ids(): void
    {
        $html = $this->vacancyHtml('100')
            . $this->vacancyHtml('100');

        $this->mockHomeAndPage($html);

        Http::fake([
            self::BASE_URL . 'Detail/100' => Http::response(
                $this->detailHtml(),
                200
            ),
        ]);

        $vacancies = $this->makeSource()->fetch();

        $this->assertCount(1, $vacancies);

        Http::assertSentCount(3);
    }

    public function test_fetch_skips_titles_outside_technology_scope_without_requesting_details(): void
    {
        $html = $this->vacancyHtml(
            '100',
            'Analista Financeiro Júnior'
        );

        $this->mockHomeAndPage($html);

        $vacancies = $this->makeSource()->fetch();

        $this->assertSame([], $vacancies);

        // Apenas home e listagem. Nenhum detalhe foi consultado.
        Http::assertSentCount(2);
    }

    public function test_fetch_skips_senior_titles_without_requesting_details(): void
    {
        $html = $this->vacancyHtml(
            '100',
            'Desenvolvedor PHP Sênior'
        );

        $this->mockHomeAndPage($html);

        $vacancies = $this->makeSource()->fetch();

        $this->assertSame([], $vacancies);

        Http::assertSentCount(2);
    }

    public function test_fetch_ignores_details_without_job_posting(): void
    {
        $this->mockHomeAndPage($this->vacancyHtml('100'));

        Http::fake([
            self::BASE_URL . 'Detail/100' => Http::response(
                '<html><body>Sem dados estruturados</body></html>',
                200
            ),
        ]);

        $vacancies = $this->makeSource()->fetch();

        $this->assertSame([], $vacancies);
    }

    public function test_fetch_ignores_vacancy_without_original_publication_date(): void
    {
        $this->mockHomeAndPage($this->vacancyHtml('100'));

        Http::fake([
            self::BASE_URL . 'Detail/100' => Http::response(
                $this->detailHtml(datePosted: ''),
                200
            ),
        ]);

        $vacancies = $this->makeSource()->fetch();

        $this->assertSame([], $vacancies);
    }

    public function test_fetch_ignores_vacancy_rejected_by_location_filter(): void
    {
        $filter = Mockery::mock(VacancyLocationFilter::class);

        $filter->shouldReceive('isEligibleForCollection')
            ->once()
            ->with(
                'Rio do Sul, SC, Brazil',
                'Técnico de Suporte de TI - Júnior',
                Mockery::type('string'),
                'Presencial'
            )
            ->andReturn(false);

        $this->mockHomeAndPage($this->vacancyHtml('100'));

        Http::fake([
            self::BASE_URL . 'Detail/100' => Http::response(
                $this->detailHtml(),
                200
            ),
        ]);

        $vacancies = $this->makeSource($filter)->fetch();

        $this->assertSame([], $vacancies);
    }

    public function test_fetch_throws_exception_when_home_page_is_empty(): void
    {
        Http::fake([
            self::BASE_URL => Http::response('', 200),
        ]);

        $this->expectException(RuntimeException::class);

        $this->makeSource()->fetch();
    }

    public function test_fetch_throws_exception_when_listing_response_is_invalid(): void
    {
        Http::fake([
            self::BASE_URL => Http::response('<html>FIESC</html>', 200),

            self::BASE_URL . 'ListVacancies' => Http::response([
                'invalid' => true,
            ], 200),
        ]);

        $this->expectException(RuntimeException::class);

        $this->makeSource()->fetch();
    }

    public function test_fetch_stops_when_listing_returns_empty_html(): void
    {
        Http::fake([
            self::BASE_URL => Http::response('<html>FIESC</html>', 200),

            self::BASE_URL . 'ListVacancies' => Http::response([
                'view' => '',
                'isLast' => true,
                'subtotal' => 0,
            ], 200),
        ]);

        $vacancies = $this->makeSource()->fetch();

        $this->assertSame([], $vacancies);
    }

    public function test_fetch_returns_partial_results_when_maximum_page_limit_is_reached(): void
    {
        Http::fake(function (Request $request) {
            if (
                $request->method() === 'GET'
                && $request->url() === self::BASE_URL
            ) {
                return Http::response('<html>FIESC</html>', 200);
            }

            if ($request->url() === self::BASE_URL . 'ListVacancies') {
                return Http::response([
                    'view' => $this->vacancyHtml('100'),
                    'isLast' => false,
                    'subtotal' => 2,
                ], 200);
            }

            if ($request->url() === self::BASE_URL . 'Detail/100') {
                return Http::response($this->detailHtml(), 200);
            }

            return Http::response('Not found', 404);
        });

        $vacancies = $this->makeSource(maxPages: 1)->fetch();

        $this->assertCount(1, $vacancies);
        $this->assertSame(
            'pandape:fiesc:100',
            $vacancies[0]['external_id']
        );

        Http::assertSentCount(3);
    }

    public function test_fetch_throws_exception_when_detail_request_fails(): void
    {
        $this->mockHomeAndPage($this->vacancyHtml('100'));

        Http::fake([
            self::BASE_URL . 'Detail/100' => Http::response(
                'Erro interno',
                500
            ),
        ]);

        $this->expectException(
            \Illuminate\Http\Client\RequestException::class
        );

        $this->makeSource()->fetch();
    }

    public function test_fetch_supports_job_posting_inside_graph(): void
    {
        $job = [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'Organization',
                    'name' => 'FIESC',
                ],
                [
                    '@type' => 'JobPosting',
                    'title' => 'Técnico de Suporte de TI - Júnior',
                    'description' => 'Atuação em suporte técnico.',
                    'datePosted' => '2026-11-04T09:51:01',
                    'jobLocation' => [
                        'address' => [
                            'addressLocality' => 'Rio do Sul',
                            'addressRegion' => 'SC',
                            'addressCountry' => 'BR',
                        ],
                    ],
                ],
            ],
        ];

        $this->mockHomeAndPage($this->vacancyHtml('100'));

        Http::fake([
            self::BASE_URL . 'Detail/100' => Http::response(
                '<script type="application/ld+json">'
                    . json_encode($job, JSON_UNESCAPED_UNICODE)
                    . '</script>',
                200
            ),
        ]);

        $vacancies = $this->makeSource()->fetch();

        $this->assertCount(1, $vacancies);

        $this->assertSame(
            'Técnico de Suporte de TI - Júnior',
            $vacancies[0]['title']
        );

        $this->assertSame(
            'Rio do Sul, SC, BR',
            $vacancies[0]['location']
        );
    }
}
