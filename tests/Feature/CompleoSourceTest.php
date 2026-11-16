<?php

namespace Tests\Feature;

use App\Sources\CompleoSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CompleoSourceTest extends TestCase
{
    public function test_it_collects_only_provider_it_jobs_from_the_public_sitemap(): void
    {
        $sitemap = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url><loc>https://jobs.compleo.app/providerit/jobdetail/WR10316M</loc></url>
    <url><loc>https://jobs.compleo.app/another-company/jobdetail/AB12345C</loc></url>
    <url><loc>https://jobs.compleo.app/providerit/home</loc></url>
</urlset>
XML;

        $posting = [
            '@context' => 'https://schema.org',
            '@type' => 'JobPosting',
            'title' => 'Analista de Infraestrutura Júnior',
            'description' => '<p>Atuação híbrida em Botafogo, Rio de Janeiro. Conhecimentos em redes e infraestrutura.</p>',
            'datePosted' => '2026-11-10T12:00:00-03:00',
            'employmentType' => 'FULL_TIME',
            'hiringOrganization' => [
                '@type' => 'Organization',
                'name' => 'Provider IT',
            ],
            'jobLocation' => [
                '@type' => 'Place',
                'address' => [
                    '@type' => 'PostalAddress',
                    'addressLocality' => 'Rio de Janeiro',
                    'addressRegion' => 'RJ',
                    'addressCountry' => 'BR',
                ],
            ],
            'url' => 'https://jobs.compleo.app/providerit/jobdetail/WR10316M',
        ];

        Http::fake([
            'jobs.compleo.app/sitemap.xml' => Http::response($sitemap, 200),
            'jobs.compleo.app/providerit/jobdetail/WR10316M' => Http::response(
                '<html><head><script type="application/ld+json">'
                    . json_encode($posting, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    . '</script></head><body></body></html>',
                200
            ),
        ]);

        $source = new CompleoSource(
            companyKey: 'providerit',
            companyName: 'Provider IT',
        );

        $vacancies = collect($source->fetch());

        $this->assertCount(1, $vacancies);
        $this->assertSame('Analista de Infraestrutura Júnior', $vacancies[0]['title']);
        $this->assertSame('Provider IT', $vacancies[0]['company']);
        $this->assertSame(
            'https://jobs.compleo.app/providerit/jobdetail/WR10316M',
            $vacancies[0]['application_url']
        );
        $this->assertSame('compleo:providerit:WR10316M', $vacancies[0]['external_id']);
        $this->assertSame('Híbrido', $vacancies[0]['work_model']);
        $this->assertSame('Rio de Janeiro', $vacancies[0]['city']);
        $this->assertSame('RJ', $vacancies[0]['state']);
        $this->assertSame('2026-11-10T15:00:00+00:00', $vacancies[0]['original_published_at']);

        Http::assertSentCount(2);
    }

    public function test_it_does_not_collect_a_job_without_original_publication_date(): void
    {
        $posting = [
            '@type' => 'JobPosting',
            'title' => 'Desenvolvedor Júnior',
            'description' => '<p>Vaga para desenvolvimento de software no Brasil.</p>',
            'hiringOrganization' => ['name' => 'Provider IT'],
            'jobLocation' => [
                'address' => [
                    'addressLocality' => 'Rio de Janeiro',
                    'addressRegion' => 'RJ',
                    'addressCountry' => 'BR',
                ],
            ],
        ];

        Http::fake([
            'jobs.compleo.app/sitemap.xml' => Http::response(
                '<urlset><url><loc>https://jobs.compleo.app/providerit/jobdetail/NO12345A</loc></url></urlset>',
                200
            ),
            'jobs.compleo.app/providerit/jobdetail/NO12345A' => Http::response(
                '<script type="application/ld+json">'
                    . json_encode($posting, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    . '</script>',
                200
            ),
        ]);

        $source = new CompleoSource(
            companyKey: 'providerit',
            companyName: 'Provider IT',
        );

        $this->assertSame([], collect($source->fetch())->all());
        Http::assertSentCount(2);
    }

    public function test_it_throws_when_the_sitemap_has_no_provider_jobs(): void
    {
        Http::fake([
            'jobs.compleo.app/sitemap.xml' => Http::response(
                '<urlset><url><loc>https://jobs.compleo.app/another-company/jobdetail/AB12345C</loc></url></urlset>',
                200
            ),
        ]);

        $source = new CompleoSource(
            companyKey: 'providerit',
            companyName: 'Provider IT',
        );

        $this->expectException(\RuntimeException::class);
        $source->fetch();
    }
}
