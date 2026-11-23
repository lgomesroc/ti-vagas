<?php

namespace Tests\Feature;

use App\Services\VacancySourceFactory;
use App\Sources\ProgramathorSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProgramathorSourceTest extends TestCase
{
    public function test_programathor_source_is_registered_in_factory(): void
    {
        $source = app(VacancySourceFactory::class)->make('programathor');

        $this->assertInstanceOf(ProgramathorSource::class, $source);
        $this->assertSame('programathor', $source->name());
    }

    public function test_external_reposted_vacancies_are_not_imported(): void
    {
        Http::fake([
            'programathor.com.br/jobs-api*' => Http::response($this->listingHtml(), 200),
            'programathor.com.br/jobs/12345-*' => Http::response($this->externalVacancyHtml(), 200),
        ]);

        $source = new ProgramathorSource(
            maxPages: 1,
            requestDelayMs: 0,
        );

        $this->assertSame([], $source->fetch());
    }

    public function test_programathor_is_not_classified_as_a_linkedin_aggregator(): void
    {
        $domains = config('linkedin.aggregator_domains', []);

        $this->assertNotContains('programathor.com.br', $domains);
        $this->assertNotContains('recrutor.com.br', $domains);
    }

    private function listingHtml(): string
    {
        return <<<'HTML'
<!doctype html>
<html lang="pt-BR">
<body>
    <a href="/jobs/12345-vaga-externa">Vaga externa</a>
</body>
</html>
HTML;
    }

    private function externalVacancyHtml(): string
    {
        return <<<'HTML'
<!doctype html>
<html lang="pt-BR">
<head><meta property="article:published_time" content="2026-11-20T10:00:00-03:00"></head>
<body>
    <main>
        <h1>VAGA EXTERNA — Desenvolvedor PHP Júnior</h1>
        <p>Empresa: Empresa de Tecnologia</p>
        <p>Localização: Remoto</p>
        <h2>Descrição da vaga</h2>
        <p>Atuar no desenvolvimento de aplicações web utilizando PHP e Laravel. Participar da manutenção de APIs, correção de problemas e evolução de sistemas existentes.</p>
    </main>
</body>
</html>
HTML;
    }
}
