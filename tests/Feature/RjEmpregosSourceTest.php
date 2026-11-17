<?php

namespace Tests\\Feature;

use App\\Services\\VacancySourceFactory;
use App\\Sources\\RjEmpregosSource;
use Illuminate\\Support\\Facades\\Http;
use Tests\\TestCase;

class RjEmpregosSourceTest extends TestCase
{
    public function test_it_collects_a_post_and_preserves_the_original_external_application_url(): void
    {
        Http::fake([
            'rjempregos.net/feed/*' => Http::response($this->feed(), 200, ['Content-Type' => 'application/rss+xml']),
            'rjempregos.net/vagasrio/*' => Http::response($this->article('https://qualitydigital.gupy.io/jobs/12869427'), 200),
        ]);

        $source = new RjEmpregosSource();
        $vacancies = iterator_to_array($source->fetch());

        $this->assertCount(1, $vacancies);
        $this->assertSame('Desenvolvedor PHP Júnior', $vacancies[0]['title']);
        $this->assertSame('Quality Digital', $vacancies[0]['company']);
        $this->assertSame('https://qualitydigital.gupy.io/jobs/12869427', $vacancies[0]['application_url']);
        $this->assertSame('2026-11-12T11:43:00+00:00', $vacancies[0]['original_published_at']);
    }

    public function test_it_ignores_posts_without_a_direct_external_application_link(): void
    {
        Http::fake([
            'rjempregos.net/feed/*' => Http::response($this->feed(), 200),
            'rjempregos.net/vagasrio/*' => Http::response($this->article('https://rjempregos.net/vagasrio/redirect/'), 200),
        ]);

        $source = new RjEmpregosSource();
        $this->assertCount(0, iterator_to_array($source->fetch()));
    }

    public function test_it_does_not_collect_posts_without_a_published_date(): void
    {
        $feed = str_replace('<pubDate>Thu, 12 Nov 2026 11:43:00 +0000</pubDate>', '<pubDate></pubDate>', $this->feed());

        Http::fake([
            'rjempregos.net/feed/*' => Http::response($feed, 200),
        ]);

        $source = new RjEmpregosSource();
        $this->assertCount(0, iterator_to_array($source->fetch()));
    }

    public function test_it_can_be_resolved_by_the_vacancy_source_factory(): void
    {
        config([
            'vacancy_sources.rjempregos' => [
                'base_url' => 'https://rjempregos.net',
                'max_pages' => 3,
                'max_jobs' => 30,
            ],
        ]);

        $source = app(VacancySourceFactory::class)->make('rjempregos');

        $this->assertInstanceOf(RjEmpregosSource::class, $source);
        $this->assertSame('rjempregos', $source->name());
    }

    private function feed(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <item>
      <title>Desenvolvedor PHP Júnior - Quality Digital</title>
      <link>https://rjempregos.net/vagasrio/desenvolvedor-php-junior-quality-digital/</link>
      <guid>https://rjempregos.net/?p=12345</guid>
      <pubDate>Thu, 12 Nov 2026 11:43:00 +0000</pubDate>
    </item>
  </channel>
</rss>
XML;
    }

    private function article(string $applicationUrl): string
    {
        return <<<HTML
<!doctype html>
<html>
<body>
  <article>
    <h1>Desenvolvedor PHP Júnior</h1>
    <div class="entry-content">
      <p>Empresa: Quality Digital</p>
      <p>Ramo de atuação: Tecnologia</p>
      <p>Descrição da vaga: Desenvolvimento de APIs e aplicações PHP.</p>
      <p>Requisitos: PHP e Laravel.</p>
      <p>Regime de contratação: Efetivo</p>
      <p>Local: Home Office</p>
      <a href="{$applicationUrl}">Candidatar-se no site da empresa</a>
    </div>
  </article>
</body>
</html>
HTML;
    }
}
