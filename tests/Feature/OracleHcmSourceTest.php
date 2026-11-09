<?php

namespace Tests\Feature;

use App\Sources\OracleHcmSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OracleHcmSourceTest extends TestCase
{
    public function test_it_extracts_country_from_structured_api_fields(): void
    {
        Http::fake([
            'iaziqy.fa.ocs.oraclecloud.com/hcmRestApi/resources/latest/recruitingCEJobRequisitions*'
                => Http::response([
                    'items' => [
                        [
                            'requisitionList' => [
                                $this->posting([
                                    'PrimaryLocation' => 'Brasil',
                                    'PrimaryLocationCountry' => 'BR',
                                ]),
                            ],
                        ],
                    ],
                ], 200),

            'iaziqy.fa.ocs.oraclecloud.com/hcmRestApi/resources/latest/recruitingCEJobRequisitionDetails*'
                => Http::response([
                    'items' => [
                        $this->detail([
                            'PrimaryLocation' => 'Brasil',
                            'PrimaryLocationCountry' => 'BR',
                        ]),
                    ],
                ], 200),
        ]);

        $source = $this->makeSource();

        $vacancies = iterator_to_array($source->fetch());

        $this->assertCount(1, $vacancies);
        $this->assertSame('BR', $vacancies[0]['country']);
        $this->assertNull($vacancies[0]['city']);
        $this->assertNull($vacancies[0]['state']);
    }

    public function test_it_does_not_infer_city_or_state_from_location_text(): void
    {
        Http::fake([
            'iaziqy.fa.ocs.oraclecloud.com/hcmRestApi/resources/latest/recruitingCEJobRequisitions*'
                => Http::response([
                    'items' => [
                        [
                            'requisitionList' => [
                                $this->posting([
                                    'PrimaryLocation' => 'Niterói, RJ, Brasil',
                                    'PrimaryLocationCountry' => 'BR',
                                ]),
                            ],
                        ],
                    ],
                ], 200),

            'iaziqy.fa.ocs.oraclecloud.com/hcmRestApi/resources/latest/recruitingCEJobRequisitionDetails*'
                => Http::response([
                    'items' => [
                        $this->detail([
                            'PrimaryLocation' => 'Niterói, RJ, Brasil',
                            'PrimaryLocationCountry' => 'BR',
                        ]),
                    ],
                ], 200),
        ]);

        $source = $this->makeSource();

        $vacancies = iterator_to_array($source->fetch());

        $this->assertCount(1, $vacancies);
        $this->assertSame('BR', $vacancies[0]['country']);
        $this->assertNull($vacancies[0]['city']);
        $this->assertNull($vacancies[0]['state']);
    }

    private function makeSource(): OracleHcmSource
    {
        return new OracleHcmSource(
            siteNumber: 'CX_1',
            siteName: 'Empresa',
            companyName: 'Empresa Teste',
            maxJobs: 10,
        );
    }

    private function posting(array $overrides = []): array
    {
        return array_replace([
            'Id' => '12345',
            'Title' => 'Software Developer Junior',
            'PrimaryLocation' => 'Brasil',
            'PrimaryLocationCountry' => 'BR',
            'PostedDate' => '2026-11-01',
        ], $overrides);
    }

    private function detail(array $overrides = []): array
    {
        return array_replace([
            'Id' => '12345',
            'Title' => 'Software Developer Junior',
            'ExternalDescriptionStr' => 'Desenvolvimento de aplicações e sistemas.',
            'ExternalPostedStartDate' => '2026-11-01',
            'PrimaryLocation' => 'Brasil',
            'PrimaryLocationCountry' => 'BR',
            'WorkplaceType' => 'Remote',
            'JobSchedule' => 'Full time',
        ], $overrides);
    }
}
