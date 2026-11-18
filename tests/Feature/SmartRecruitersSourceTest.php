<?php

namespace Tests\Feature;

use App\Sources\SmartRecruitersSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmartRecruitersSourceTest extends TestCase
{
    private const LIST_URL =
        'https://api.smartrecruiters.com/v1/companies/test-company/postings';

    private const DETAIL_URL =
        'https://api.smartrecruiters.com/v1/companies/test-company/postings/12345';

    public function test_it_fetches_posting_details_and_normalizes_a_brazilian_junior_vacancy(): void
    {
        Http::fake([
            self::LIST_URL . '?*' => Http::response([
                'limit' => 100,
                'offset' => 0,
                'totalFound' => 1,
                'content' => [[
                    'id' => '12345',
                    'name' => 'Desenvolvedor PHP Júnior',
                ]],
            ]),
            self::DETAIL_URL => Http::response($this->details()),
        ]);

        $vacancies = (new SmartRecruitersSource(
            'test-company',
            'Empresa de Teste'
        ))->fetch();

        $this->assertCount(1, $vacancies);
        $this->assertSame('Desenvolvedor PHP Júnior', $vacancies[0]['title']);
        $this->assertSame('Empresa de Teste', $vacancies[0]['company']);
        $this->assertSame('BR', $vacancies[0]['country']);
        $this->assertSame(
            'https://jobs.smartrecruiters.com/test-company/12345-dev-php',
            $vacancies[0]['application_url']
        );
        $this->assertSame('test-company:12345', $vacancies[0]['external_id']);
        $this->assertSame('Tempo integral', $vacancies[0]['employment_type']);
    }

    public function test_it_discards_postings_outside_brazil(): void
    {
        Http::fake([
            self::LIST_URL . '?*' => Http::response([
                'totalFound' => 1,
                'content' => [['id' => '12345']],
            ]),
            self::DETAIL_URL => Http::response($this->details([
                'location' => [
                    'city' => 'Lisbon',
                    'region' => 'Lisbon',
                    'country' => 'pt',
                    'remote' => false,
                ],
            ])),
        ]);

        $vacancies = (new SmartRecruitersSource(
            'test-company',
            'Empresa de Teste'
        ))->fetch();

        $this->assertCount(0, $vacancies);
    }

    public function test_it_uses_the_company_identifier_as_source_identity(): void
    {
        $source = new SmartRecruitersSource('acme-br', 'ACME');

        $this->assertSame('smartrecruiters-acme-br', $source->name());
    }

    private function details(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => '12345',
            'name' => 'Desenvolvedor PHP Júnior',
            'active' => true,
            'releasedDate' => '2026-11-10T10:00:00.000Z',
            'location' => [
                'city' => 'Rio de Janeiro',
                'region' => 'RJ',
                'country' => 'br',
                'remote' => false,
            ],
            'typeOfEmployment' => ['label' => 'Full-time'],
            'applyUrl' => 'https://jobs.smartrecruiters.com/test-company/12345-dev-php',
            'jobAd' => [
                'sections' => [
                    'jobDescription' => [
                        'text' => '<p>Atuação com PHP e Laravel.</p>',
                    ],
                    'qualifications' => [
                        'text' => '<p>Conhecimentos em PHP.</p>',
                    ],
                ],
            ],
        ], $overrides);
    }
}
