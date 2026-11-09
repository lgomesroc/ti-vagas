<?php

namespace Tests\Feature;

use App\Sources\InhireSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InhireSourceTest extends TestCase
{
    public function test_it_extracts_geographic_fields_from_structured_data(): void
    {
        $this->mockInhire([
            'location' => [
                'city' => 'Florianópolis',
                'state' => 'Santa Catarina',
                'stateCode' => 'SC',
                'country' => 'Brasil',
                'countryCode' => 'BR',
                'latitude' => -27.5954,
                'longitude' => -48.5480,
            ],
        ]);

        $vacancies = iterator_to_array($this->makeSource()->fetch());

        $this->assertCount(1, $vacancies);

        $vacancy = $vacancies[0];

        $this->assertSame('Florianópolis', $vacancy['city']);
        $this->assertSame('Santa Catarina', $vacancy['state']);
        $this->assertSame('SC', $vacancy['state_code']);
        $this->assertSame('Brasil', $vacancy['country']);
        $this->assertSame('BR', $vacancy['country_code']);
        $this->assertEquals(-27.5954, $vacancy['latitude']);
        $this->assertEquals(-48.5480, $vacancy['longitude']);
    }

    public function test_it_does_not_infer_city_or_state_from_location_text(): void
    {
        $this->mockInhire([
            'location' => 'Niterói, RJ, Brasil',
        ]);

        $vacancies = iterator_to_array($this->makeSource()->fetch());

        $this->assertCount(1, $vacancies);

        $vacancy = $vacancies[0];

        $this->assertNull($vacancy['city']);
        $this->assertNull($vacancy['state']);
        $this->assertNull($vacancy['state_code']);
        $this->assertNull($vacancy['country']);
        $this->assertNull($vacancy['country_code']);
        $this->assertNull($vacancy['latitude']);
        $this->assertNull($vacancy['longitude']);

        $this->assertSame(
            'Niterói, RJ, Brasil',
            $vacancy['location']
        );
    }

    public function test_it_preserves_brazil_wide_remote_vacancies(): void
    {
        $this->mockInhire([
            'location' => 'BR',
            'workplaceType' => 'remote',
        ]);

        $vacancies = iterator_to_array($this->makeSource()->fetch());

        $this->assertCount(1, $vacancies);
        $this->assertSame('Brasil', $vacancies[0]['location']);
        $this->assertSame('Remoto', $vacancies[0]['work_model']);
    }

    private function makeSource(): InhireSource
    {
        return new InhireSource(
            tenant: 'empresa-teste',
            companyName: 'Empresa Teste',
            maxJobs: 10,
        );
    }

    private function mockInhire(array $overrides = []): void
    {
        $job = [
            'jobId' => '12345',
            'status' => 'published',
            'displayName' => 'Desenvolvedor de Software Junior',
            'location' => 'Brasil',
            'workplaceType' => 'remote',
            'publishedAt' => '2026-11-01T10:00:00Z',
        ];

        $details = array_replace([
            'jobId' => '12345',
            'displayName' => 'Desenvolvedor de Software Junior',
            'description' => 'Desenvolvimento de aplicações e sistemas.',
            'location' => 'Brasil',
            'workplaceType' => 'remote',
            'publishedAt' => '2026-11-01T10:00:00Z',
            'contractType' => 'CLT',
        ], $overrides);

        Http::fake([
            'api.inhire.app/job-posts/public/pages' => Http::response([
                'jobsPage' => [$job],
            ], 200),

            'api.inhire.app/job-posts/public/pages/12345' => Http::response(
                $details,
                200
            ),
        ]);
    }
}
