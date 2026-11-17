<?php

namespace Tests\Feature;

use App\Sources\TeamtailorSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TeamtailorSourceTest extends TestCase
{
    private const FEED_URL = 'https://jobs.example.com/jobs.rss';

    public function test_it_imports_a_brazilian_junior_job_from_the_public_rss_feed(): void
    {
        Http::fake([
            self::FEED_URL . '*' => Http::response($this->feed(), 200, [
                'Content-Type' => 'application/rss+xml',
            ]),
        ]);

        $source = new TeamtailorSource(
            companyKey: 'example',
            companyName: 'Empresa Exemplo',
            feedUrl: self::FEED_URL,
        );

        $vacancies = $source->fetch();

        $this->assertCount(1, $vacancies);
        $this->assertSame('Desenvolvedor PHP Júnior', $vacancies[0]['title']);
        $this->assertSame('Empresa Exemplo', $vacancies[0]['company']);
        $this->assertSame('Belo Horizonte, Brazil', $vacancies[0]['location']);
        $this->assertSame('Híbrido', $vacancies[0]['work_model']);
        $this->assertSame(
            'https://jobs.example.com/jobs/123',
            $vacancies[0]['application_url']
        );
        $this->assertSame(
            '2026-11-10T09:00:00+00:00',
            $vacancies[0]['original_published_at']
        );
    }

    public function test_it_ignores_jobs_without_a_brazilian_location(): void
    {
        Http::fake([
            self::FEED_URL . '*' => Http::response($this->feed(
                locationName: 'Lisbon, Portugal',
                city: 'Lisbon',
                country: 'Portugal',
            ), 200),
        ]);

        $source = new TeamtailorSource('example', 'Empresa Exemplo', self::FEED_URL);

        $this->assertCount(0, $source->fetch());
    }

    public function test_it_does_not_import_a_job_without_original_publication_date(): void
    {
        Http::fake([
            self::FEED_URL . '*' => Http::response($this->feed(pubDate: ''), 200),
        ]);

        $source = new TeamtailorSource('example', 'Empresa Exemplo', self::FEED_URL);

        $this->assertCount(0, $source->fetch());
    }

    private function feed(
        string $locationName = 'Belo Horizonte, Brazil',
        string $city = 'Belo Horizonte',
        string $country = 'Brazil',
        string $pubDate = 'Tue, 10 Nov 2026 09:00:00 +0000',
    ): string {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<rss version="2.0" xmlns:tt="https://www.teamtailor.com">'
            . '<channel><item>'
            . '<title>Desenvolvedor PHP Júnior</title>'
            . '<description><![CDATA[<p>Atuação com PHP e Laravel em Belo Horizonte.</p>]]></description>'
            . '<link>https://jobs.example.com/jobs/123</link>'
            . '<guid>job-123</guid>'
            . ($pubDate !== '' ? '<pubDate>' . $pubDate . '</pubDate>' : '')
            . '<remoteStatus>hybrid</remoteStatus>'
            . '<tt:locations><tt:location>'
            . '<tt:name>' . $locationName . '</tt:name>'
            . '<tt:city>' . $city . '</tt:city>'
            . '<tt:country>' . $country . '</tt:country>'
            . '</tt:location></tt:locations>'
            . '</item></channel></rss>';
    }
}
