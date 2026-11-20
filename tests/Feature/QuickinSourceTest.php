<?php

namespace Tests\Feature;

use App\Sources\QuickinSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QuickinSourceTest extends TestCase
{
    public function test_it_returns_an_empty_result_when_the_public_jobs_endpoint_is_empty(): void
    {
        Http::fake([
            'https://careers.example.com' => Http::response(
                '<html><body><div data-account-id="0123456789abcdef01234567"></div></body></html>',
                200
            ),
            'https://api.quickin.io/public/0123456789abcdef01234567/jobs*' => Http::response([], 200),
        ]);

        $source = new QuickinSource(
            companyKey: 'example',
            companyName: 'Empresa Exemplo',
            url: 'https://careers.example.com',
        );

        $this->assertSame('quickin-example', $source->name());
        $this->assertSame([], collect($source->fetch())->all());
    }
}
