<?php

namespace Tests\Feature;

use App\Sources\JobylonSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class JobylonSourceTest extends TestCase
{
    public function test_it_returns_an_empty_result_when_the_career_page_has_no_job_links(): void
    {
        Http::fake([
            'https://careers.example.com/jobs' => Http::response('<html><body><h1>Carreiras</h1></body></html>', 200),
        ]);

        $source = new JobylonSource(
            companyKey: 'example',
            companyName: 'Empresa Exemplo',
            companyId: 123,
            careerUrl: 'https://careers.example.com/jobs',
        );

        $this->assertSame('jobylon:example', $source->name());
        $this->assertSame([], collect($source->fetch())->all());
    }
}
