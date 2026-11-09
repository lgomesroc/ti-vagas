<?php

namespace Tests\Feature;

use App\Sources\LeverSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LeverSourceTest extends TestCase
{
    public function test_it_extracts_country_from_structured_api_field(): void
    {
        Http::fake([
            'api.lever.co/v0/postings/*' => Http::response([
                $this->posting([
                    'country' => 'BR',
                    'categories' => [
                        'location' => 'Remoto',
                        'allLocations' => ['Brasil'],
                        'commitment' => 'Full-time',
                    ],
                ]),
            ], 200),
        ]);

        $source = new LeverSource(
            'empresa-teste',
            'Empresa Teste'
        );

        $vacancies = iterator_to_array($source->fetch());

        $this->assertCount(1, $vacancies);
        $this->assertSame('BR', $vacancies[0]['country']);
        $this->assertNull($vacancies[0]['city']);
        $this->assertNull($vacancies[0]['state']);
    }

    public function test_it_does_not_infer_city_or_state_from_location_text(): void
    {
        Http::fake([
            'api.lever.co/v0/postings/*' => Http::response([
                $this->posting([
                    'country' => 'BR',
                    'categories' => [
                        'location' => 'Niterói, RJ, Brasil',
                        'allLocations' => [],
                        'commitment' => 'Full-time',
                    ],
                ]),
            ], 200),
        ]);

        $source = new LeverSource(
            'empresa-teste',
            'Empresa Teste'
        );

        $vacancies = iterator_to_array($source->fetch());

        $this->assertCount(1, $vacancies);
        $this->assertSame('BR', $vacancies[0]['country']);
        $this->assertNull($vacancies[0]['city']);
        $this->assertNull($vacancies[0]['state']);
    }

    private function posting(array $overrides = []): array
    {
        return array_replace([
            'id' => 'vaga-123',
            'text' => 'Desenvolvedor PHP Junior',
            'country' => 'BR',
            'categories' => [
                'location' => 'Brasil',
                'allLocations' => [],
                'commitment' => 'Full-time',
            ],
            'descriptionPlain' => 'Desenvolvimento de aplicações PHP.',
            'workplaceType' => 'remote',
            'createdAt' => 1794222000000,
            'applyUrl' => 'https://jobs.example.com/apply/123',
            'hostedUrl' => 'https://jobs.example.com/123',
        ], $overrides);
    }
}
