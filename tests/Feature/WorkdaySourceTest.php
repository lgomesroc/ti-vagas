<?php

namespace Tests\\Feature;

use App\\Sources\\WorkdaySource;
use Illuminate\\Support\\Facades\\Http;
use Tests\\TestCase;

class WorkdaySourceTest extends TestCase
{
    private const ENDPOINT =
        'https://pwc.wd3.myworkdayjobs.com/wday/cxs/pwc/pwc/Global_Experienced_Careers/jobs';

    public function test_it_collects_a_junior_vacancy_and_fetches_its_details(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response([
                'total' => 1,
                'jobPostings' => [[
                    'title' => 'Desenvolvedor C# Júnior',
                    'externalPath' => '/job/Rio-de-Janeiro/123',
                    'locationsText' => 'Rio de Janeiro, Brazil',
                ]],
            ]),
            'https://pwc.wd3.myworkdayjobs.com/wday/cxs/pwc/pwc/Global_Experienced_Careers/job/Rio-de-Janeiro/123'
                => Http::response([
                    'jobPostingInfo' => [
                        'title' => 'Desenvolvedor C# Júnior',
                        'jobDescription' => '<p>Desenvolvimento de aplicações.</p>',
                        'startDate' => '2026-11-10',
                        'location' => 'Rio de Janeiro, Brazil',
                        'remoteType' => 'Onsite',
                        'jobReqId' => 'REQ-123',
                        'externalUrl' => 'https://pwc.example/jobs/REQ-123',
                        'timeType' => 'full_time',
                    ],
                ]),
        ]);

        $source = new WorkdaySource(
            host: 'pwc.wd3.myworkdayjobs.com',
            tenant: 'pwc',
            site: 'Global_Experienced_Careers',
            companyName: 'PwC',
            locale: 'pt-BR',
        );

        $vacancies = collect($source->fetch())->all();

        $this->assertCount(1, $vacancies);
        $this->assertSame('PwC', $vacancies[0]['company']);
        $this->assertSame('REQ-123', $vacancies[0]['external_id']);
        $this->assertSame('Presencial', $vacancies[0]['work_model']);
        $this->assertSame('Tempo integral', $vacancies[0]['employment_type']);
        $this->assertSame('https://pwc.example/jobs/REQ-123', $vacancies[0]['application_url']);
        $this->assertStringNotContainsString('<p>', $vacancies[0]['description']);
    }

    public function test_it_stops_when_the_workday_listing_is_empty(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response([
                'total' => 0,
                'jobPostings' => [],
            ]),
        ]);

        $source = new WorkdaySource(
            host: 'pwc.wd3.myworkdayjobs.com',
            tenant: 'pwc',
            site: 'Global_Experienced_Careers',
            companyName: 'PwC',
        );

        $this->assertSame([], collect($source->fetch())->all());
    }
}
