<?php

namespace Tests\Feature;

use App\Sources\GupySource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GupySourceTest extends TestCase
{
    public function test_it_extracts_a_job_posting_from_next_data_and_json_ld(): void
    {
        $baseUrl = 'https://empresa-teste.gupy.io';
        $nextData = json_encode([
            'props' => ['pageProps' => ['jobs' => [['id' => 456]]]],
        ], JSON_THROW_ON_ERROR);
        $listing = '<html><body><script id="__NEXT_DATA__" type="application/json">'
            . $nextData
            . '</script><a href="/jobs/456">Vaga</a></body></html>';

        $jobPosting = [
            '@context' => 'https://schema.org',
            '@type' => 'JobPosting',
            'title' => 'Desenvolvedor C# Júnior',
            'description' => '<p>Desenvolvimento de sistemas.</p>',
            'datePosted' => '2026-11-10',
            'employmentType' => ['FULL_TIME'],
            'jobLocation' => [[
                'address' => [
                    'addressLocality' => 'Rio de Janeiro',
                    'addressRegion' => ['name' => 'Rio de Janeiro'],
                    'addressCountry' => ['name' => 'Brasil'],
                ],
            ]],
            'jobLocationType' => 'TELECOMMUTE',
        ];
        $detail = '<html><body><script type="application/ld+json">'
            . json_encode($jobPosting, JSON_THROW_ON_ERROR)
            . '</script></body></html>';

        Http::fake([
            $baseUrl . '/' => Http::response($listing),
            $baseUrl . '/jobs/456' => Http::response($detail),
        ]);

        $source = new GupySource(
            subdomain: 'empresa-teste',
            companyName: 'Empresa Teste',
            baseUrl: $baseUrl,
        );

        $vacancies = collect($source->fetch())->all();

        $this->assertCount(1, $vacancies);
        $this->assertSame('Desenvolvedor C# Júnior', $vacancies[0]['title']);
        $this->assertSame('Empresa Teste', $vacancies[0]['company']);
        $this->assertSame('456', $vacancies[0]['external_id']);
        $this->assertSame('Rio de Janeiro', $vacancies[0]['city']);
        $this->assertSame('Brasil', $vacancies[0]['country']);
        $this->assertSame('remote', $vacancies[0]['work_model']);
        $this->assertSame('full_time', $vacancies[0]['employment_type']);
    }

    public function test_it_discards_talent_pool_announcements(): void
    {
        $baseUrl = 'https://empresa-teste.gupy.io';
        $listing = '<script id="__NEXT_DATA__" type="application/json">'
            . json_encode(['props' => ['pageProps' => ['jobs' => [['id' => 789]]]]])
            . '</script><a href="/jobs/789">Vaga</a>';
        $posting = [
            '@type' => 'JobPosting',
            'title' => 'Banco de Talentos de Tecnologia',
            'description' => 'Cadastre seu currículo.',
        ];
        $detail = '<script type="application/ld+json">'
            . json_encode($posting)
            . '</script>';

        Http::fake([
            $baseUrl . '/' => Http::response($listing),
            $baseUrl . '/jobs/789' => Http::response($detail),
        ]);

        $source = new GupySource('empresa-teste', 'Empresa Teste', baseUrl: $baseUrl);

        $this->assertSame([], collect($source->fetch())->all());
    }
}
