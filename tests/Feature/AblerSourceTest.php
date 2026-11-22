<?php

namespace Tests\Feature;

use App\Sources\AblerSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AblerSourceTest extends TestCase
{
    public function test_it_collects_and_normalizes_a_published_junior_technology_vacancy(): void
    {
        Http::fake([
            'candidatos.abler.com.br/api/candidate/v2/vacancies*' => Http::response([
                'data' => [[
                    'type' => 'vacancy',
                    'id' => '987',
                    'attributes' => [
                        'id' => '987',
                        'title' => 'Desenvolvedor PHP Júnior',
                        'description' => '<p>Desenvolvimento de aplicações PHP.</p>',
                        'created_at' => now()->subDays(2)->toISOString(),
                        'url' => 'https://abler.in/v987',
                        'status' => 'published',
                        'company' => 'Empresa Exemplo',
                        'workplace_type' => 'remoto',
                        'city' => 'Brasil',
                        'state' => '',
                        'country' => 'Brasil',
                    ],
                ]],
                'meta' => ['page' => 1, 'next' => null, 'last' => 1],
            ], 200),
        ]);

        $source = new AblerSource(maxPages: 2, pageSize: 12);

        $vacancies = collect($source->fetch());

        $this->assertSame('abler-global', $source->name());
        $this->assertCount(1, $vacancies);
        $this->assertSame('Desenvolvedor PHP Júnior', $vacancies[0]['title']);
        $this->assertSame('Empresa Exemplo', $vacancies[0]['company']);
        $this->assertSame('Remoto', $vacancies[0]['work_model']);
        $this->assertSame('abler-global:987', $vacancies[0]['external_id']);
    }

    public function test_it_stops_when_the_public_vacancy_list_is_empty(): void
    {
        Http::fake([
            'candidatos.abler.com.br/api/candidate/v2/vacancies*' => Http::response([
                'data' => [],
                'meta' => ['page' => 1, 'next' => null, 'last' => 1],
            ], 200),
        ]);

        $source = new AblerSource();

        $this->assertSame([], collect($source->fetch())->all());
    }
}
