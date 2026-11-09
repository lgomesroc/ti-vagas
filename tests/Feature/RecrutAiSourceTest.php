<?php

namespace Tests\Feature;

use App\Sources\RecrutAiSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RecrutAiSourceTest extends TestCase
{
    public function test_it_extracts_geographic_fields_from_json_ld(): void
    {
        $this->mockRecrutAi(
            $this->jsonLd([
                '@context' => 'https://schema.org',
                '@type' => 'JobPosting',
                'title' => 'Desenvolvedor Backend',
                'description' => 'Desenvolvimento de aplicações.',
                'datePosted' => '2026-11-09',
                'employmentType' => 'FULL_TIME',
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

        $vacancies = iterator_to_array(
            $this->makeSource()->fetch()
        );

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
            'Florianópolis, Santa Catarina',
            $vacancy['location']
        );

        $this->assertSame(
            'https://empresa.recrut.ai/job/99999',
            $vacancy['application_url']
        );

        $this->assertSame('99999', $vacancy['external_id']);
    }

    public function test_it_does_not_infer_geographic_fields_from_listing_text(): void
    {
        $this->mockRecrutAi(
            $this->jsonLd([
                '@context' => 'https://schema.org',
                '@type' => 'JobPosting',
                'title' => 'Desenvolvedor Backend',
                'description' => 'Desenvolvimento de aplicações.',
                'datePosted' => '2026-11-09',
                'employmentType' => 'FULL_TIME',
            ]),
            'Niterói, RJ - Oportunidade presencial'
        );

        $vacancies = iterator_to_array(
            $this->makeSource()->fetch()
        );

        $this->assertCount(1, $vacancies);

        $vacancy = $vacancies[0];

        $this->assertNull($vacancy['city']);
        $this->assertNull($vacancy['state']);
        $this->assertNull($vacancy['state_code']);
        $this->assertNull($vacancy['country']);
        $this->assertNull($vacancy['country_code']);
        $this->assertNull($vacancy['latitude']);
        $this->assertNull($vacancy['longitude']);

        $this->assertSame('', $vacancy['location']);
        $this->assertSame('Presencial', $vacancy['work_model']);
    }

    private function makeSource(): RecrutAiSource
    {
        return new RecrutAiSource(
            tenant: 'empresa',
            companyName: 'Empresa de Teste',
            domain: 'empresa.recrut.ai',
            maxJobs: 10,
        );
    }

    private function mockRecrutAi(
        string $structuredData,
        string $listingText
    ): void {
        $listingHtml = <<<'HTML'
<!DOCTYPE html>
<html>
<body>
    <div class="card">
        <h5>Desenvolvedor Backend</h5>
        <p>__LISTING_TEXT__</p>
        <a href="/job/99999">Ver oportunidade</a>
    </div>
</body>
</html>
HTML;

        $listingHtml = str_replace(
            '__LISTING_TEXT__',
            htmlspecialchars($listingText, ENT_QUOTES, 'UTF-8'),
            $listingHtml
        );

        $detailHtml = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    __STRUCTURED_DATA__
</head>
<body>
    <main>
        Detalhes da oportunidade
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
            'empresa.recrut.ai/company/public-jobs/*' => Http::response([
                'html' => $listingHtml,
            ], 200),

            'empresa.recrut.ai/job/99999' => Http::response(
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
