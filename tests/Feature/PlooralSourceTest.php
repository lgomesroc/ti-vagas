<?php

namespace Tests\Feature;

use App\Sources\PlooralSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PlooralSourceTest extends TestCase
{
    public function test_it_returns_an_empty_result_when_the_public_positions_feed_is_empty(): void
    {
        Http::fake([
            'https://api.enlizt.com/v1/public/feed/example/positions*' => Http::response([], 200),
        ]);

        $source = new PlooralSource(
            companyKey: 'example',
            companyName: 'Empresa Exemplo',
            subdomain: 'example',
        );

        $this->assertSame('plooral-example', $source->name());
        $this->assertSame([], collect($source->fetch())->all());
    }
}
