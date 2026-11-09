<?php

namespace Tests\Feature;

use App\Sources\AshbySource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AshbySourceTest extends TestCase
{
    private const API_URL =
        'https://api.ashbyhq.com/posting-api/job-board/test-board';

    public function test_it_extracts_structured_geographic_fields_from_posting(): void
    {
        Http::fake([
            self::API_URL => Http::response([
                'jobs' => [
                    $this->posting(),
                ],
            ], 200),
        ]);

        $source = new AshbySource('test-board', 'Empresa de Teste');

        $vacancies = $source->fetch();

        $this->assertCount(1, $vacancies);

        $vacancy = $vacancies[0];

        $this->assertSame('Niterói', $vacancy['city']);
        $this->assertSame('Rio de Janeiro', $vacancy['state']);
        $this->assertSame('Brazil', $vacancy['country']);
        $this->assertSame(
            'Niterói, Rio de Janeiro, Brazil',
            $vacancy['location']
        );
    }

    public function test_it_does_not_infer_structured_fields_from_free_text_location(): void
    {
        Http::fake([
            self::API_URL => Http::response([
                'jobs' => [
                    $this->posting([
                        'location' => 'Niterói, RJ, Brasil',
                        'address' => [
                            'postalAddress' => [],
                        ],
                    ]),
                ],
            ], 200),
        ]);

        $source = new AshbySource('test-board', 'Empresa de Teste');

        $vacancies = $source->fetch();

        $this->assertCount(1, $vacancies);

        $vacancy = $vacancies[0];

        $this->assertNull($vacancy['city']);
        $this->assertNull($vacancy['state']);
        $this->assertNull($vacancy['country']);
        $this->assertSame(
            'Niterói, RJ, Brasil',
            $vacancy['location']
        );
    }

    private function posting(array $overrides = []): array
    {
        return array_replace([
            'id' => 'ashby-test-001',
            'title' => 'Desenvolvedor PHP Júnior',
            'isListed' => true,
            'descriptionPlain' => 'Oportunidade para desenvolvedor PHP júnior.',
            'publishedAt' => '2026-11-09T09:00:00.000Z',
            'workplaceType' => 'Onsite',
            'isRemote' => false,
            'employmentType' => 'Full-time',
            'applyUrl' => 'https://example.com/jobs/ashby-test-001',
            'jobUrl' => 'https://example.com/jobs/ashby-test-001',
            'location' => 'Niterói, Rio de Janeiro, Brazil',
            'address' => [
                'postalAddress' => [
                    'addressLocality' => 'Niterói',
                    'addressRegion' => 'Rio de Janeiro',
                    'addressCountry' => 'Brazil',
                ],
            ],
        ], $overrides);
    }
}
