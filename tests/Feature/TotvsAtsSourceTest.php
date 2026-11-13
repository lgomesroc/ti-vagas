<?php

namespace Tests\\Feature;

use App\\Sources\\TotvsAtsSource;
use Illuminate\\Support\\Facades\\Http;
use Tests\\TestCase;

class TotvsAtsSourceTest extends TestCase
{
    public function test_it_returns_no_vacancies_when_the_listing_has_no_cards(): void
    {
        Http::fake([
            'careers.example.com/*' => Http::response(
                '<html><body><main><p>Nenhuma oportunidade encontrada.</p></main></body></html>'
            ),
        ]);

        $source = new TotvsAtsSource(
            companyKey: 'empresa-teste',
            companyName: 'Empresa Teste',
            url: 'https://careers.example.com/vagas',
        );

        $this->assertSame('totvs_ats:empresa-teste', $source->name());
        $this->assertSame([], collect($source->fetch())->all());

        Http::assertSentCount(1);
    }
}
