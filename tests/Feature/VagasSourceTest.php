<?php

namespace Tests\Feature;

use App\Sources\VagasSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VagasSourceTest extends TestCase
{
    public function test_it_returns_an_empty_result_when_the_company_page_has_no_opportunity_links(): void
    {
        Http::fake([
            'https://trabalheconosco.vagas.com.br/example' => Http::response(
                '<html><body><h1>Trabalhe conosco</h1></body></html>',
                200
            ),
        ]);

        $source = new VagasSource(
            companyKey: 'example',
            companyName: 'Empresa Exemplo',
            url: 'https://trabalheconosco.vagas.com.br/example',
        );

        $this->assertSame('vagas:example', $source->name());
        $this->assertSame([], collect($source->fetch())->all());
    }
}
