<?php

namespace Tests\Feature;

use App\Sources\CsodSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CsodSourceTest extends TestCase
{
    public function test_it_bootstraps_the_public_token_and_imports_brazilian_jobs(): void
    {
        $home = 'https://bradesco.csod.com/ux/ats/careersite/1/home?c=bradesco';
        $api = 'https://us.api.csod.com/rec-job-search/external/jobs';

        Http::fake([
            $home => Http::response(
                '<script>csod.context = {"token":"anonymous-token","endpoints":{"cloud":"https://us.api.csod.com"},"cultureID":1,"cultureName":"pt-BR"};</script>'
            ),
            $api => Http::response([
                'status' => 'Success',
                'data' => [
                    'totalCount' => 1,
                    'requisitions' => [[
                        'requisitionId' => 92753,
                        'displayJobTitle' => 'Desenvolvedor Backend Júnior',
                        'externalDescription' => '<p>Desenvolvimento de APIs e serviços.</p>',
                        'postingEffectiveDate' => '11/10/2026',
                        'locations' => [[
                            'city' => 'Osasco',
                            'state' => 'São Paulo',
                            'country' => 'Brasil',
                        ]],
                    ]],
                ],
            ]),
        ]);

        $source = new CsodSource(
            companyKey: 'bradesco',
            companyName: 'Bradesco',
            careerSiteUrl: $home,
        );

        $vacancies = collect($source->fetch())->all();

        $this->assertCount(1, $vacancies);
        $this->assertSame('Desenvolvedor Backend Júnior', $vacancies[0]['title']);
        $this->assertSame('Bradesco', $vacancies[0]['company']);
        $this->assertSame('92753', $vacancies[0]['external_id']);
        $this->assertStringContainsString('/requisition/92753?c=bradesco', $vacancies[0]['application_url']);
        $this->assertSame('Osasco, São Paulo, Brasil', $vacancies[0]['location']);
    }

    public function test_it_stops_when_the_search_returns_no_more_jobs(): void
    {
        $home = 'https://bradesco.csod.com/ux/ats/careersite/1/home?c=bradesco';
        $api = 'https://us.api.csod.com/rec-job-search/external/jobs';

        Http::fake([
            $home => Http::response(
                '<script>csod.context = {"token":"anonymous-token","endpoints":{"cloud":"https://us.api.csod.com"}};</script>'
            ),
            $api => Http::response([
                'status' => 'Success',
                'data' => ['totalCount' => 0, 'requisitions' => []],
            ]),
        ]);

        $source = new CsodSource('bradesco', 'Bradesco', $home);

        $this->assertSame([], collect($source->fetch())->all());
    }
}
