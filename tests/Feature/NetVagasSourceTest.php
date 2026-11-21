<?php

namespace Tests\Feature;

use App\Sources\NetVagasSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NetVagasSourceTest extends TestCase
{
    public function test_it_collects_recent_public_announcements_from_search_pages(): void
    {
        Http::fake([
            '*netvagas.com.br/empresa/anuncios/cargo/desenvolvedor/*' => Http::response($this->listingHtml(), 200),
        ]);

        $source = new NetVagasSource(
            terms: ['desenvolvedor'],
            maxPages: 1,
            initialLookbackDays: 60,
        );

        $vacancies = $source->fetch();

        $this->assertCount(1, $vacancies);
        $this->assertSame('Desenvolvedor PHP Júnior', $vacancies[0]['title']);
        $this->assertSame('Empresa de Tecnologia', $vacancies[0]['company']);
        $this->assertSame(
            'https://www.netvagas.com.br/empresa/anuncios/a1234567/desenvolvedor-php-junior/',
            $vacancies[0]['application_url']
        );
        $this->assertSame('netvagas:1234567', $vacancies[0]['external_id']);
        $this->assertStringContainsString('Laravel', $vacancies[0]['description']);
    }

    private function listingHtml(): string
    {
        return <<<'HTML'
<!doctype html>
<html lang="pt-BR">
<body>
    <article>
        <h2><a href="/empresa/anuncios/a1234567/desenvolvedor-php-junior/">Desenvolvedor PHP Júnior</a></h2>
        <a href="/empresa/empresa-de-tecnologia/">Empresa de Tecnologia</a>
        <p>1 vaga</p>
        <p>CLT</p>
        <p>Há 1 dia</p>
        <h3>Descrição</h3>
        <p>Atuar no desenvolvimento e manutenção de aplicações web utilizando PHP e Laravel. Participar da criação de APIs e correção de problemas.</p>
        <p>Local de trabalho: RIO DE JANEIRO - RJ</p>
    </article>
</body>
</html>
HTML;
    }
}
