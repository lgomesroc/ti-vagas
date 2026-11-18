<?php

namespace Tests\Feature;

use App\Sources\CwiSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CwiSourceTest extends TestCase
{
    private const LISTING_URL = 'https://cwi.com.br/talentos/oportunidades/';
    private const DETAIL_URL = 'https://cwi.com.br/talentos/oportunidade/desenvolvedor-php-junior-301';

    public function test_it_extracts_a_junior_opportunity_from_listing_and_detail_pages(): void
    {
        Http::fake([
            self::LISTING_URL => Http::response($this->listingHtml(), 200),
            self::DETAIL_URL => Http::response($this->detailHtml(), 200),
        ]);

        $vacancies = collect((new CwiSource())->fetch())->all();

        $this->assertCount(1, $vacancies);
        $this->assertSame('Desenvolvedor PHP Júnior', $vacancies[0]['title']);
        $this->assertSame('CWI Software', $vacancies[0]['company']);
        $this->assertSame('Remoto', $vacancies[0]['work_model']);
        $this->assertSame('São Leopoldo - RS, Brasil', $vacancies[0]['location']);
        $this->assertSame(
            'https://cwi.com.br/perfil/?opportunity=desenvolvedor-php-junior-301',
            $vacancies[0]['application_url']
        );
        $this->assertSame('cwi:desenvolvedor-php-junior-301', $vacancies[0]['external_id']);
        $this->assertSame('2026-11-10T13:00:00+00:00', $vacancies[0]['original_published_at']);
        $this->assertStringContainsString('PHP 8', $vacancies[0]['description']);
    }

    public function test_it_ignores_opportunities_without_a_verifiable_original_publication_date(): void
    {
        Http::fake([
            self::LISTING_URL => Http::response($this->listingHtml(), 200),
            self::DETAIL_URL => Http::response($this->detailHtml(false), 200),
            'https://cwi.com.br/wp-json/wp/v2/types' => Http::response([], 200),
        ]);

        $this->assertSame([], collect((new CwiSource())->fetch())->all());
    }

    public function test_it_recovers_the_original_publication_date_from_the_wordpress_rest_api(): void
    {
        Http::fake([
            self::LISTING_URL => Http::response($this->listingHtml(), 200),
            self::DETAIL_URL => Http::response($this->detailHtml(false), 200),
            'https://cwi.com.br/wp-json/wp/v2/types' => Http::response([
                'opportunity' => ['rest_base' => 'opportunities', 'viewable' => true],
            ], 200),
            'https://cwi.com.br/wp-json/wp/v2/opportunities*' => Http::response([
                ['slug' => 'desenvolvedor-php-junior-301', 'date_gmt' => '2026-11-10T13:00:00'],
            ], 200),
        ]);

        $vacancies = collect((new CwiSource())->fetch())->all();

        $this->assertCount(1, $vacancies);
        $this->assertSame('2026-11-10T13:00:00+00:00', $vacancies[0]['original_published_at']);
    }

    private function listingHtml(): string
    {
        return <<<'HTML'
        <html><body>
            <a href="#desenvolvimento" class="tabs__link" data-tabs-item-id="desenvolvimento">Desenvolvimento</a>
            <a href="https://cwi.com.br/talentos/oportunidade/desenvolvedor-php-junior-301" class="opportunity-card__link">
                <span class="opportunity-card__text">Desenvolvedor PHP Júnior</span>
                <div class="pills">
                    <span class="pills__pill">Remoto</span>
                    <span class="pills__pill">São Leopoldo - RS</span>
                </div>
            </a>
        </body></html>
        HTML;
    }

    private function detailHtml(bool $withDate = true): string
    {
        $date = $withDate
            ? '<meta property="article:published_time" content="2026-11-10T10:00:00-03:00">'
            : '';

        return <<<HTML
        <html><head>{$date}</head><body>
            <h1>Desenvolvedor PHP Júnior</h1>
            <p>Atuação no desenvolvimento de soluções de software.</p>
            <div class="opportunity-description__content">
                <ul class="checklist">
                    <li class="opportunity-description__checklist-item">Conhecimento em PHP 8.</li>
                    <li class="opportunity-description__checklist-item">Conhecimento em Laravel.</li>
                </ul>
                <a class="opportunity-description__link" href="https://cwi.com.br/perfil/?opportunity=desenvolvedor-php-junior-301">Candidate-se</a>
            </div>
        </body></html>
        HTML;
    }
}
