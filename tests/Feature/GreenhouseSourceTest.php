<?php

namespace Tests\\Feature;

use App\\Sources\\GreenhouseSource;
use Illuminate\\Support\\Facades\\Http;
use Tests\\TestCase;

class GreenhouseSourceTest extends TestCase
{
    public function test_it_normalizes_a_valid_junior_vacancy(): void
    {
        Http::fake([
            'boards-api.greenhouse.io/v1/boards/test-board/jobs*' => Http::response([
                'jobs' => [[
                    'id' => 123,
                    'title' => 'Desenvolvedor PHP Júnior',
                    'first_published' => '2026-11-10T10:00:00Z',
                    'absolute_url' => 'https://boards.greenhouse.io/test-board/jobs/123',
                    'location' => ['name' => 'Rio de Janeiro, RJ'],
                    'content' => '<p>Desenvolvimento de aplicações.</p><ul><li>PHP</li></ul>',
                ]],
            ]),
        ]);

        $source = new GreenhouseSource('test-board', 'Empresa Teste');
        $vacancies = collect($source->fetch())->all();

        $this->assertCount(1, $vacancies);
        $this->assertSame('Empresa Teste', $vacancies[0]['company']);
        $this->assertSame('123', $vacancies[0]['external_id']);
        $this->assertSame(
            'https://boards.greenhouse.io/test-board/jobs/123',
            $vacancies[0]['application_url']
        );
        $this->assertStringContainsString('Desenvolvimento', $vacancies[0]['description']);
        $this->assertStringNotContainsString('<p>', $vacancies[0]['description']);
    }

    public function test_it_ignores_incomplete_job_records(): void
    {
        Http::fake([
            'boards-api.greenhouse.io/v1/boards/test-board/jobs*' => Http::response([
                'jobs' => [['id' => 123, 'title' => 'Desenvolvedor PHP Júnior']],
            ]),
        ]);

        $source = new GreenhouseSource('test-board', 'Empresa Teste');

        $this->assertSame([], collect($source->fetch())->all());
    }
}
