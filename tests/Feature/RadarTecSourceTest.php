<?php

namespace Tests\Feature;

use App\Sources\RadarTecSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RadarTecSourceTest extends TestCase
{
    public function test_it_extracts_geographic_fields_from_json_ld(): void
    {
        $this->mockRadarTec(
            $this->jsonLd([
                '@context' => 'https://schema.org',
                '@type' => 'JobPosting',
                'jobLocation' => [
                    '@type' => 'Place',
                    'address' => [
                        '@type' => 'PostalAddress',
                        'addressLocality' => 'Florianópolis',
                        'addressRegion' => 'Santa Catarina',
                        'addressRegionCode' => 'SC',
                        'addressCountry' => 'Brasil',
                        'addressCountryCode' => 'BR',
                    ],
                    'geo' => [
                        '@type' => 'GeoCoordinates',
                        'latitude' => -27.5954,
                        'longitude' => -48.5480,
                    ],
                ],
            ]),
            'Florianópolis, SC'
        );

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

        $this->assertSame(
            'Florianópolis, SC, Brasil',
            $vacancy['location']
        );
    }

    public function test_it_does_not_infer_city_or_state_from_card_location_text(): void
    {
        $this->mockRadarTec('', 'Niterói, RJ');

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

    private function makeSource(): RadarTecSource
    {
        return new RadarTecSource(maxJobs: 10);
    }

    private function mockRadarTec(
        string $structuredData,
        string $cardLocation
    ): void {
        $listHtml = <<<'HTML'
<!DOCTYPE html>
<html>
<body>
    <div class="vote-item">
        <a class="vote-title" href="/vaga/99999/desenvolvedor-junior">
            Desenvolvedor de Software Junior
        </a>
        <span class="cidade">__CARD_LOCATION__</span>
        <span class="salario">A Combinar</span>
        <div class="info-data"><p>09/11/2026</p></div>
        <div class="vote-info">
            <p>Oportunidade para desenvolvedor de software junior.</p>
        </div>
    </div>
</body>
</html>
HTML;

        $listHtml = str_replace(
            '__CARD_LOCATION__',
            htmlspecialchars($cardLocation, ENT_QUOTES, 'UTF-8'),
            $listHtml
        );

        $detailHtml = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    __STRUCTURED_DATA__
</head>
<body>
    <main>
        Tipo de contrato CLT
        Descrição
        Desenvolvimento e manutenção de aplicações de software.
        há 2 dias
        Vagas relacionadas
    </main>
</body>
</html>
HTML;

        $detailHtml = str_replace(
            '__STRUCTURED_DATA__',
            $structuredData,
            $detailHtml
        );

        Http::fake([
            'www.radartec.com.br/pesquisar/vaga*' => Http::response(
                $listHtml,
                200
            ),

            'www.radartec.com.br/vaga/99999/*' => Http::response(
                $detailHtml,
                200
            ),
        ]);
    }

    private function jsonLd(array $data): string
    {
        return '<script type="application/ld+json">'
            . json_encode(
                $data,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
            . '</script>';
    }
}
