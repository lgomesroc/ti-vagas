<?php

namespace Tests\Feature;

use App\Sources\ZohoRecruitSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZohoRecruitSourceTest extends TestCase
{
    public function test_it_collects_brazilian_remote_job_without_country_using_configured_country(): void
    {
        Http::fake([
            'spassu.zohorecruit.com/recruit/v2/public/Job_Openings*' => Http::response([
                'code' => 200,
                'data' => [
                    $this->posting([
                        'Remote_Job' => 'Sim',
                        'City' => '',
                        'State' => '',
                        'Country' => '',
                    ]),
                ],
            ], 200),
        ]);

        $source = new ZohoRecruitSource(
            subdomain: 'spassu',
            pageName: 'Careers',
            companyName: 'Spassu',
            remoteCountry: 'BR',
        );

        $vacancies = iterator_to_array($source->fetch());

        $this->assertCount(1, $vacancies);
        $this->assertSame('Remoto', $vacancies[0]['work_model']);
        $this->assertSame('Brasil (remoto)', $vacancies[0]['location']);
        $this->assertSame('Brasil', $vacancies[0]['country']);
        $this->assertSame('BR', $vacancies[0]['country_code']);
        $this->assertNull($vacancies[0]['city']);
        $this->assertNull($vacancies[0]['state']);
    }

    public function test_it_normalizes_brazilian_country_with_html_tags(): void
    {
        Http::fake([
            'spassu.zohorecruit.com/recruit/v2/public/Job_Openings*' => Http::response([
                'code' => 200,
                'data' => [
                    $this->posting([
                        'Remote_Job' => '',
                        'City' => 'Rio de Janeiro',
                        'State' => 'Rio de Janeiro',
                        'Country' => '<font>Brasil</font>',
                    ]),
                ],
            ], 200),
        ]);

        $source = new ZohoRecruitSource(
            'spassu',
            'Careers',
            'Spassu',
            'BR'
        );

        $vacancies = iterator_to_array($source->fetch());

        $this->assertCount(1, $vacancies);
        $this->assertSame('Brasil', $vacancies[0]['country']);
        $this->assertSame('BR', $vacancies[0]['country_code']);
        $this->assertSame('Rio de Janeiro', $vacancies[0]['city']);
        $this->assertSame('RJ', $vacancies[0]['state_code']);
        $this->assertSame('Rio de Janeiro', $vacancies[0]['state']);
        $this->assertNull($vacancies[0]['work_model']);
    }

    public function test_it_does_not_collect_jobs_from_foreign_countries(): void
    {
        Http::fake([
            'spassu.zohorecruit.com/recruit/v2/public/Job_Openings*' => Http::response([
                'code' => 200,
                'data' => [
                    $this->posting([
                        'Country' => 'United States',
                        'City' => 'New York',
                        'State' => 'New York',
                        'Remote_Job' => '',
                    ]),
                ],
            ], 200),
        ]);

        $source = new ZohoRecruitSource(
            'spassu',
            'Careers',
            'Spassu',
            'BR'
        );

        $vacancies = iterator_to_array($source->fetch());

        $this->assertCount(0, $vacancies);
    }

    public function test_it_ignores_unpublished_jobs(): void
    {
        Http::fake([
            'spassu.zohorecruit.com/recruit/v2/public/Job_Openings*' => Http::response([
                'code' => 200,
                'data' => [
                    $this->posting([
                        'Publish' => false,
                    ]),
                ],
            ], 200),
        ]);

        $source = new ZohoRecruitSource(
            'spassu',
            'Careers',
            'Spassu',
            'BR'
        );

        $vacancies = iterator_to_array($source->fetch());

        $this->assertCount(0, $vacancies);
    }

    private function posting(array $overrides = []): array
    {
        return array_replace([
            'Remote_Job' => '',
            'Posting_Title' => 'Desenvolvedor PHP Junior',
            'City' => 'Rio de Janeiro',
            'Industry' => 'Tecnologia',
            'Job_Description' => '<p>Desenvolvimento de aplicações PHP.</p>',
            'Job_Type' => 'Efetivo',
            'Job_Opening_Name' => 'Desenvolvedor PHP Junior',
            'State' => 'Rio de Janeiro',
            'Country' => 'Brasil',
            '$url' => 'https://spassu.zohorecruit.com/jobs/Careers/123/Desenvolvedor-PHP-Junior',
            'id' => '123',
            'Publish' => true,
            'Date_Opened' => '10/11/2026',
            'Keep_on_Career_Site' => true,
        ], $overrides);
    }
}
