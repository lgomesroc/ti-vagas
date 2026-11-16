<?php

namespace Tests\Feature;

use App\Sources\SolidesSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SolidesSourceTest extends TestCase
{
    public function test_it_collects_active_company_jobs_using_the_original_created_at_date(): void
    {
        Http::fake([
            'apigw.solides.com.br/jobs/v3/companies/nethouserj/vacancies*' => Http::response([
                'totalPages' => 1,
                'currentPage' => 1,
                'count' => 1,
                'data' => [[
                    'id' => 831454,
                    'title' => 'Analista de Suporte e Redes N1 Júnior',
                    'description' => '<p>Suporte técnico e redes.</p>',
                    'currentState' => 'em_andamento',
                    'companyName' => 'NHT FIBER LTDA',
                    'city' => ['name' => 'Rio de Janeiro'],
                    'state' => ['name' => 'Rio de Janeiro', 'code' => 'RJ'],
                    'address' => [
                        'country' => ['name' => 'Brasil'],
                        'latitude' => null,
                        'longitude' => null,
                    ],
                    'slug' => 'nethouserj',
                    'jobType' => 'presencial',
                    'homeOffice' => false,
                    'salary' => [
                        'showRangeToApplicant' => true,
                        'initialRange' => 1600,
                        'finalRange' => 2200,
                    ],
                    'recruitmentContractType' => [
                        ['name' => 'CLT'],
                    ],
                    'createdAt' => '2026-04-16',
                    'isHiddenJob' => false,
                    'isDisabled' => false,
                    'hardSkills' => [['name' => 'Suporte TI']],
                    'benefits' => [['name' => 'Vale transporte']],
                ]],
            ], 200),
        ]);

        $source = new SolidesSource(
            companyKey: 'nethouserj',
            companyName: 'NHT FIBER LTDA',
            slug: 'nethouserj',
            careerPageUrl: 'https://nethouserj.vagas.solides.com.br',
        );

        $vacancies = collect($source->fetch());

        $this->assertCount(1, $vacancies);
        $this->assertSame('NHT FIBER LTDA', $vacancies[0]['company']);
        $this->assertSame('solides:nethouserj:831454', $vacancies[0]['external_id']);
        $this->assertSame(
            'https://nethouserj.vagas.solides.com.br/vaga/831454',
            $vacancies[0]['application_url']
        );
        $this->assertSame('Presencial', $vacancies[0]['work_model']);
        $this->assertSame('CLT', $vacancies[0]['employment_type']);
        $this->assertSame('R$ 1.600 - R$ 2.200', $vacancies[0]['salary']);
        $this->assertSame(
            '2026-04-16T00:00:00+00:00',
            $vacancies[0]['original_published_at']
        );
        $this->assertStringContainsString('Suporte TI', $vacancies[0]['description']);
        Http::assertSentCount(1);
    }

    public function test_it_discards_jobs_without_a_reliable_original_publication_date(): void
    {
        Http::fake([
            'apigw.solides.com.br/jobs/v3/companies/nethouserj/vacancies*' => Http::response([
                'totalPages' => 1,
                'data' => [[
                    'id' => 739739,
                    'title' => 'Analista de Suporte N2 - Redes',
                    'description' => '<p>Suporte de redes.</p>',
                    'currentState' => 'em_andamento',
                    'city' => ['name' => 'Rio de Janeiro'],
                    'state' => ['name' => 'Rio de Janeiro', 'code' => 'RJ'],
                    'jobType' => 'presencial',
                    'isHiddenJob' => false,
                    'isDisabled' => false,
                ]],
            ], 200),
        ]);

        $source = new SolidesSource(
            companyKey: 'nethouserj',
            companyName: 'NHT FIBER LTDA',
            slug: 'nethouserj',
            careerPageUrl: 'https://nethouserj.vagas.solides.com.br',
        );

        $this->assertSame([], collect($source->fetch())->all());
    }

    public function test_it_respects_the_api_maximum_page_size_and_reads_all_pages(): void
    {
        Http::fake(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            if (($query['page'] ?? '1') === '1') {
                return Http::response([
                    'totalPages' => 2,
                    'data' => [],
                ], 200);
            }

            return Http::response([
                'totalPages' => 2,
                'data' => [],
            ], 200);
        });

        $source = new SolidesSource(
            companyKey: 'nethouserj',
            companyName: 'NHT FIBER LTDA',
            slug: 'nethouserj',
            careerPageUrl: 'https://nethouserj.vagas.solides.com.br',
            pageSize: 100,
        );

        $this->assertSame([], collect($source->fetch())->all());

        Http::assertSent(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            return ($query['take'] ?? null) === '25';
        });
    }
}
