<?php

namespace Tests\Feature;

use App\Sources\GeekHunterSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeekHunterSourceTest extends TestCase
{
    private const SEARCH_URL =
        'https://www.geekhunter.com/pt/vagas?experienceLevel=entry&workModality=remote%2Cremote-in-city';

    private const JOB_URL =
        'https://www.geekhunter.com/pt/empresa/jobs/desenvolvedor-php-junior';

    public function test_it_extracts_structured_geographic_fields_from_job_posting(): void
    {
        $this->mockJobPage([
            'jobLocation' => [
                [
                    '@type' => 'Place',
                    'address' => [
                        '@type' => 'PostalAddress',
                        'addressLocality' => 'Niterói',
                        'addressRegion' => 'Rio de Janeiro',
                        'addressCountry' => 'Brazil',
                    ],
                ],
            ],
        ]);

        $source = new GeekHunterSource();

        $vacancies = $source->fetch();

        $this->assertCount(1, $vacancies);

        $vacancy = $vacancies[0];

        $this->assertSame('Niterói', $vacancy['city']);
        $this->assertSame('Rio de Janeiro', $vacancy['state']);
        $this->assertSame('Brazil', $vacancy['country']);
        $this->assertSame(
            'Niterói, Rio de Janeiro, Brazil (remoto)',
            $vacancy['location']
        );
    }

    public function test_it_does_not_infer_geographic_fields_from_description_text(): void
    {
        $this->mockJobPage([
            'description' => 'Vaga para desenvolvedor PHP júnior. Localização mencionada: Niterói, RJ, Brasil.',
            'jobLocation' => [
                [
                    '@type' => 'Place',
                    'address' => [
                        'streetAddress' => 'Rua de teste, 100',
                    ],
                ],
            ],
        ]);

        $source = new GeekHunterSource();

        $vacancies = $source->fetch();

        $this->assertCount(1, $vacancies);

        $vacancy = $vacancies[0];

        $this->assertNull($vacancy['city']);
        $this->assertNull($vacancy['state']);
        $this->assertNull($vacancy['country']);
        $this->assertSame('Brasil (remoto)', $vacancy['location']);
    }

    private function mockJobPage(array $overrides = []): void
    {
        $posting = array_replace([
            '@context' => 'https://schema.org',
            '@type' => 'JobPosting',
            'title' => 'Desenvolvedor PHP Júnior',
            'description' => 'Oportunidade para desenvolvedor PHP júnior com Laravel.',
            'datePosted' => '2026-11-09T09:00:00Z',
            'employmentType' => 'FULL_TIME',
            'hiringOrganization' => [
                '@type' => 'Organization',
                'name' => 'Empresa de Teste',
            ],
            'jobLocation' => [
                [
                    '@type' => 'Place',
                    'address' => [
                        'addressLocality' => 'Niterói',
                        'addressRegion' => 'Rio de Janeiro',
                        'addressCountry' => 'Brazil',
                    ],
                ],
            ],
        ], $overrides);

        $json = json_encode(
            $posting,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $jobHtml = '<html><head>'
            . '<script type="application/ld+json">'
            . $json
            . '</script>'
            . '</head><body><h1>Desenvolvedor PHP Júnior</h1></body></html>';

        Http::fake([
            self::SEARCH_URL => Http::response(
                '<html><a href="' . self::JOB_URL . '">Vaga</a></html>',
                200
            ),
            self::JOB_URL => Http::response($jobHtml, 200),
        ]);
    }
}
