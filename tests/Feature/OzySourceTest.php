<?php

namespace Tests\\Feature;

use App\\Sources\\OzySource;
use Illuminate\\Support\\Facades\\Http;
use Tests\\TestCase;

class OzySourceTest extends TestCase
{
    public function test_it_collects_a_junior_remote_vacancy(): void
    {
        Http::fake([
            'ozycompany.com.br/api/vagas' => Http::response([
                'vagas' => [[
                    'slug' => 'desenvolvedor-php-junior',
                    'titulo' => 'Desenvolvedor PHP Júnior',
                    'nivel' => 'Júnior',
                    'modalidade' => 'Remoto',
                    'publicadaEm' => '2026-11-10T10:00:00Z',
                    'contrato' => 'CLT',
                ]],
            ]),
            'ozycompany.com.br/api/vagas/desenvolvedor-php-junior' => Http::response([
                'vaga' => [
                    'titulo' => 'Desenvolvedor PHP Júnior',
                    'nivel' => 'Júnior',
                    'modalidade' => 'Remoto',
                    'publicadaEm' => '2026-11-10T10:00:00Z',
                    'descricao' => '<p>Desenvolvimento de aplicações PHP.</p>',
                    'responsabilidades' => '<p>Manutenção de sistemas.</p>',
                    'contrato' => 'CLT',
                ],
            ]),
        ]);

        $source = new OzySource();
        $vacancies = collect($source->fetch())->all();

        $this->assertCount(1, $vacancies);
        $this->assertSame('Ozy Company', $vacancies[0]['company']);
        $this->assertSame('desenvolvedor-php-junior', $vacancies[0]['external_id']);
        $this->assertSame('Brasil (remoto)', $vacancies[0]['location']);
        $this->assertSame('Remoto', $vacancies[0]['work_model']);
        $this->assertSame('CLT', $vacancies[0]['employment_type']);
        $this->assertStringContainsString('Desenvolvimento de aplicações PHP.', $vacancies[0]['description']);
    }

    public function test_it_rejects_senior_levels_before_requesting_details(): void
    {
        Http::fake([
            'ozycompany.com.br/api/vagas' => Http::response([
                'vagas' => [[
                    'slug' => 'engenheiro-senior',
                    'titulo' => 'Engenheiro de Software',
                    'nivel' => 'Sênior',
                ]],
            ]),
        ]);

        $source = new OzySource();

        $this->assertSame([], collect($source->fetch())->all());
        Http::assertNotSent(fn ($request) => str_contains(
            $request->url(),
            '/api/vagas/engenheiro-senior'
        ));
    }
}
