<?php

namespace Tests\Feature;

use App\Sources\SensediaSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SensediaSourceTest extends TestCase
{
    private const FEED_URL = 'https://sensedia.hire.trakstar.com/jobfeeds/sensedia';

    public function test_it_extracts_structured_geographic_fields_from_rss(): void
    {
        Http::fake([
            self::FEED_URL => Http::response(
                $this->feedWithLocation(
                    'Niterói',
                    'Rio de Janeiro',
                    'Brazil'
                ),
                200,
                ['Content-Type' => 'application/rss+xml']
            ),
        ]);

        $source = new SensediaSource();

        $vacancies = iterator_to_array(
            (function () use ($source) {
                yield from $source->fetch();
            })()
        );

        $this->assertCount(1, $vacancies);

        $vacancy = $vacancies[0];

        $this->assertSame('Niterói', $vacancy['city']);
        $this->assertSame('Rio de Janeiro', $vacancy['state']);
        $this->assertSame('Brazil', $vacancy['country']);
        $this->assertSame(
            'Niterói, Rio de Janeiro, Brazil',
            $vacancy['location']
        );
    }

    public function test_it_does_not_infer_structured_fields_from_free_text_location(): void
    {
        Http::fake([
            self::FEED_URL => Http::response(
                $this->feedWithLocation(
                    '',
                    '',
                    '',
                    'Niterói, RJ, Brasil'
                ),
                200,
                ['Content-Type' => 'application/rss+xml']
            ),
        ]);

        $source = new SensediaSource();

        $vacancies = iterator_to_array(
            (function () use ($source) {
                yield from $source->fetch();
            })()
        );

        $this->assertCount(1, $vacancies);

        $vacancy = $vacancies[0];

        $this->assertNull($vacancy['city']);
        $this->assertNull($vacancy['state']);
        $this->assertNull($vacancy['country']);

        $this->assertSame(
            'Niterói, RJ, Brasil',
            $vacancy['location']
        );
    }

    private function feedWithLocation(
        string $city,
        string $state,
        string $country,
        string $descriptionLocation = ''
    ): string {
        $locationHtml = $descriptionLocation !== ''
            ? '<p>Location: ' . $descriptionLocation . '</p>'
            : '<p>Descrição da oportunidade de teste.</p>';

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
    <channel>
        <title>Sensedia Jobs</title>
        <item>
            <title>Desenvolvedor PHP Júnior</title>
            <description><![CDATA[
                <p>Descrição da oportunidade de teste.</p>
                {$locationHtml}
            ]]></description>
            <locationCity>{$city}</locationCity>
            <locationState>{$state}</locationState>
            <locationCountry>{$country}</locationCountry>
            <positionType>full_time</positionType>
            <pubDate>Mon, 09 Nov 2026 09:00:00 GMT</pubDate>
            <guid>sensedia-test-001</guid>
            <link>https://example.com/jobs/sensedia-test-001</link>
        </item>
    </channel>
</rss>
XML;
    }
}
