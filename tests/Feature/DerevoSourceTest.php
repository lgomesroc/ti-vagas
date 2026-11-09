<?php

namespace Tests\Feature;

use App\Sources\DerevoSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DerevoSourceTest extends TestCase
{
    public function test_it_returns_geographic_fields_as_null_when_only_free_text_location_is_available(): void
    {
        Http::fake([
            'www.derevo.com.br/Home/pagina/97' => Http::response(
                $this->listingHtml(
                    'Remoto - Brasil',
                    'Remoto'
                ),
                200
            ),
        ]);

        $source = new DerevoSource();

        $vacancies = iterator_to_array($source->fetch());

        $this->assertCount(1, $vacancies);

        $vacancy = $vacancies[0];

        $this->assertSame(
            'Remoto - Brasil',
            $vacancy['location']
        );

        $this->assertSame('Remoto', $vacancy['work_model']);

        $this->assertNull($vacancy['city']);
        $this->assertNull($vacancy['state']);
        $this->assertNull($vacancy['state_code']);
        $this->assertNull($vacancy['country']);
        $this->assertNull($vacancy['country_code']);
        $this->assertNull($vacancy['latitude']);
        $this->assertNull($vacancy['longitude']);
    }

    public function test_it_preserves_existing_vacancy_fields(): void
    {
        Http::fake([
            'www.derevo.com.br/Home/pagina/97' => Http::response(
                $this->listingHtml(
                    'Remoto - Brasil',
                    'Remoto'
                ),
                200
            ),
        ]);

        $source = new DerevoSource();

        $vacancies = iterator_to_array($source->fetch());

        $this->assertCount(1, $vacancies);

        $vacancy = $vacancies[0];

        $this->assertSame(
            'Vaga para Desenvolvedor Backend Júnior',
            $vacancy['title']
        );

        $this->assertSame('Derevo', $vacancy['company']);
        $this->assertSame('Remoto - Brasil', $vacancy['location']);
        $this->assertSame('Remoto', $vacancy['work_model']);

        $this->assertSame(
            'derevo:vaga-para-desenvolvedor-backend-junior',
            $vacancy['external_id']
        );

        $this->assertSame(
            '2026-11-09T00:00:00+00:00',
            $vacancy['original_published_at']
        );

        $this->assertSame(
            'https://www.derevo.com.br/Home/pagina/97',
            $vacancy['application_url']
        );
    }

    private function listingHtml(
        string $location,
        string $workModel
    ): string {
        return <<<HTML
        <!DOCTYPE html>
        <html lang="pt-BR">
        <head>
            <meta charset="UTF-8">
            <title>Carreiras Derevo</title>
        </head>
        <body>
            <div class="mb-7">
                <h3 class="h5">Vaga para Desenvolvedor Backend Júnior</h3>
                <small>{$location}</small>
                <span class="text-primary">{$workModel}</span>
                <p>
                    Oportunidade para desenvolvedor backend júnior.
                    Conhecimentos em desenvolvimento de software.
                </p>
                <time datetime="2026-11-09">09/11/2026</time>
            </div>
        </body>
        </html>
        HTML;
    }
}
