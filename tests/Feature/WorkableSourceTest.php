<?php

namespace Tests\Feature;

use App\Sources\WorkableSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkableSourceTest extends TestCase
{
    public function test_it_normalizes_a_brazilian_junior_vacancy(): void
    {
        Http::fake([
            'apply.workable.com/api/v1/widget/accounts/test-account*' => Http::response([
                'jobs' => [[
                    'title' => 'Desenvolvedor PHP Júnior',
                    'shortcode' => 'ABC123',
                    'description' => '<p>Desenvolvimento de software.</p>',
                    'published_on' => '2026-11-10',
                    'country' => 'Brasil',
                    'city' => 'Rio de Janeiro',
                    'state' => 'RJ',
                    'locations' => [[
                        'city' => 'Rio de Janeiro',
                        'region' => 'RJ',
                        'country' => 'Brazil',
                        'countryCode' => 'BR',
                    ]],
                    'telecommuting' => false,
                    'employment_type' => 'full-time',
                    'experience' => 'Junior',
                    'application_url' => 'https://apply.workable.com/test-account/j/ABC123/',
                    'url' => 'https://apply.workable.com/test-account/j/ABC123/',
                    'salary' => 'R$ 3.000',
                    'department' => 'Tecnologia',
                ]],
            ]),
        ]);

        $source = new WorkableSource('test-account', 'Empresa Teste');
        $vacancies = collect($source->fetch())->all();

        $this->assertCount(1, $vacancies);
        $this->assertSame('Desenvolvedor PHP Júnior', $vacancies[0]['title']);
        $this->assertSame('Empresa Teste', $vacancies[0]['company']);
        $this->assertSame('ABC123', $vacancies[0]['external_id']);
        $this->assertSame('Tempo integral', $vacancies[0]['employment_type']);
        $this->assertSame('R$ 3.000', $vacancies[0]['salary']);
        $this->assertStringContainsString('Área: Tecnologia', $vacancies[0]['description']);
    }

    public function test_it_excludes_non_brazilian_vacancies(): void
    {
        Http::fake([
            'apply.workable.com/api/v1/widget/accounts/test-account*' => Http::response([
                'jobs' => [[
                    'title' => 'Software Engineer Junior',
                    'shortcode' => 'US123',
                    'description' => 'Software development.',
                    'published_on' => '2026-11-10',
                    'country' => 'United States',
                    'locations' => [[
                        'city' => 'New York',
                        'region' => 'NY',
                        'country' => 'United States',
                        'countryCode' => 'US',
                    ]],
                    'url' => 'https://apply.workable.com/test-account/j/US123/',
                ]],
            ]),
        ]);

        $source = new WorkableSource('test-account', 'Empresa Teste');

        $this->assertSame([], collect($source->fetch())->all());
    }
}
