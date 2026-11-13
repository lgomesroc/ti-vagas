<?php

namespace Tests\Feature;

use App\Models\Municipality;
use App\Models\Vacancy;
use App\Services\Geo\IbgeGeographyImporter;
use App\Services\Geo\IpLocationResolver;
use App\Services\Geo\MunicipalityDirectory;
use App\Services\Geo\VacancyLocationResolver;
use App\Services\Geo\VisitorLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsVerifiedLocationSession;
use Tests\Concerns\SeedsGeography;
use Tests\TestCase;

class RegionalizationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsGeography;
    use BuildsVerifiedLocationSession;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame([], $this->seedGeography());
    }

    public function test_imports_all_brazilian_municipalities(): void
    {
        $this->assertSame(5571, Municipality::query()->count());
    }

    public function test_all_configured_units_have_the_expected_number_of_members(): void
    {
        $counts = Municipality::query()
            ->whereNotNull('region_code')
            ->selectRaw('region_code, count(*) as total')
            ->groupBy('region_code')
            ->pluck('total', 'region_code')
            ->all();

        $this->assertSame(22, $counts['RM-RIO-DE-JANEIRO']);
        $this->assertSame(39, $counts['RM-SAO-PAULO']);
        $this->assertSame(20, $counts['RM-CAMPINAS']);
        $this->assertSame(34, $counts['RIDE-DF']);
        $this->assertSame(13, $counts['RIDE-GRANDE-TERESINA']);
        $this->assertSame(8, $counts['RIDE-PETROLINA-JUAZEIRO']);
    }

    private function scopeOf(string $name, string $uf)
    {
        $directory = app(MunicipalityDirectory::class);

        return $directory->scopeFor($directory->find($this->ibge($name, $uf)));
    }

    private function includes(string $visitorName, string $visitorUf, string $vacancyName, string $vacancyUf): bool
    {
        $directory = app(MunicipalityDirectory::class);

        return $this->scopeOf($visitorName, $visitorUf)->includes(
            $directory->find($this->ibge($vacancyName, $vacancyUf))
        );
    }

    public function test_rio_bonito_sees_the_rio_metropolitan_region_only(): void
    {
        $this->assertTrue($this->includes('Rio Bonito', 'RJ', 'Niterói', 'RJ'));
        $this->assertTrue($this->includes('Rio Bonito', 'RJ', 'Nova Iguaçu', 'RJ'));
        $this->assertTrue($this->includes('Rio Bonito', 'RJ', 'Belford Roxo', 'RJ'));
        $this->assertTrue($this->includes('Rio Bonito', 'RJ', 'Duque de Caxias', 'RJ'));
        $this->assertFalse($this->includes('Rio Bonito', 'RJ', 'Teresópolis', 'RJ'));
        $this->assertFalse($this->includes('Rio Bonito', 'RJ', 'São Paulo', 'SP'));
    }

    public function test_teresopolis_does_not_see_the_rio_metropolitan_region(): void
    {
        $this->assertFalse($this->includes('Teresópolis', 'RJ', 'Rio de Janeiro', 'RJ'));
        $this->assertFalse($this->includes('Teresópolis', 'RJ', 'Niterói', 'RJ'));
        $this->assertFalse($this->includes('Teresópolis', 'RJ', 'São Paulo', 'SP'));
        $this->assertTrue($this->includes('Teresópolis', 'RJ', 'Nova Friburgo', 'RJ'));
        $this->assertFalse($this->includes('Teresópolis', 'RJ', 'Resende', 'RJ'));
    }

    public function test_sao_paulo_and_campinas_metropolitan_regions_are_separate(): void
    {
        $this->assertTrue($this->includes('São Bernardo do Campo', 'SP', 'São Paulo', 'SP'));
        $this->assertTrue($this->includes('São Paulo', 'SP', 'Guarulhos', 'SP'));
        $this->assertFalse($this->includes('São Bernardo do Campo', 'SP', 'Campinas', 'SP'));
        $this->assertFalse($this->includes('São Bernardo do Campo', 'SP', 'Santos', 'SP'));

        $this->assertTrue($this->includes('Paulínia', 'SP', 'Campinas', 'SP'));
        $this->assertTrue($this->includes('Paulínia', 'SP', 'Sumaré', 'SP'));
        $this->assertFalse($this->includes('Paulínia', 'SP', 'São Paulo', 'SP'));
    }

    public function test_pelotas_sees_nearby_cities_and_santa_maria_does_not_see_pelotas(): void
    {
        $this->assertTrue($this->includes('Pelotas', 'RS', 'Rio Grande', 'RS'));
        $this->assertFalse($this->includes('Pelotas', 'RS', 'Santa Maria', 'RS'));
        $this->assertFalse($this->includes('Santa Maria', 'RS', 'Pelotas', 'RS'));
        $this->assertFalse($this->includes('Santa Maria', 'RS', 'Porto Alegre', 'RS'));
    }

    public function test_ride_of_the_federal_district_is_treated_like_a_metropolitan_region(): void
    {
        $this->assertTrue($this->includes('Brasília', 'DF', 'Luziânia', 'GO'));
        $this->assertTrue($this->includes('Luziânia', 'GO', 'Brasília', 'DF'));
        $this->assertTrue($this->includes('Unaí', 'MG', 'Brasília', 'DF'));
        $this->assertFalse($this->includes('Brasília', 'DF', 'Goiânia', 'GO'));
        $this->assertFalse($this->includes('Goiânia', 'GO', 'Brasília', 'DF'));
    }

    public function test_other_rides_are_loaded(): void
    {
        $this->assertTrue($this->includes('Teresina', 'PI', 'Timon', 'MA'));
        $this->assertTrue($this->includes('Petrolina', 'PE', 'Juazeiro', 'BA'));
        $this->assertFalse($this->includes('Petrolina', 'PE', 'Teresina', 'PI'));
    }

    public function test_resolves_vacancy_locations_from_free_text(): void
    {
        $resolver = app(VacancyLocationResolver::class);

        $cases = [
            ['Niterói, RJ, Brasil', 'Niterói'],
            ['Niteroi - RJ', 'Niterói'],
            ['Niterói, Rio de Janeiro, Brazil', 'Niterói'],
            ['Rio de Janeiro, Brasil', 'Rio de Janeiro'],
            ['Brasil - Rio de Janeiro', 'Rio de Janeiro'],
            ['Rio de Janeiro, Rio de Janeiro, Brazil', 'Rio de Janeiro'],
            ['Rio de Janeiro, State of Rio de Janeiro, Brazil', 'Rio de Janeiro'],
            ['São Paulo, SP', 'São Paulo'],
            ['São Paulo', 'São Paulo'],
            ['Sao Bernardo do Campo, Sao Paulo, Brasil', 'São Bernardo do Campo'],
            ['Paulínia (Híbrido)', 'Paulínia'],
            ['Paulínia - SP (Híbrido)', 'Paulínia'],
            ['Pelotas, RS', 'Pelotas'],
            ['Santa Maria, RS', 'Santa Maria'],
            ['Brasília, DF', 'Brasília'],
        ];

        foreach ($cases as [$text, $expected]) {
            $found = $resolver->resolveFromText($text);

            $this->assertSame($expected, $found['name'] ?? null, $text);
        }
    }

    public function test_city_that_does_not_exist_in_the_stated_state_is_not_resolved(): void
    {
        $resolver = app(VacancyLocationResolver::class);

        $this->assertNull($resolver->resolveFromText('Santos, Rio de Janeiro'));
        $this->assertNull($resolver->resolveFromText('Santos, RJ'));
        $this->assertSame(
            'Santos',
            $resolver->resolveFromText('Santos, SP')['name']
        );
    }

    public function test_ambiguous_city_name_without_state_is_not_resolved(): void
    {
        $resolver = app(VacancyLocationResolver::class);

        $this->assertNull($resolver->resolveFromText('Santa Maria'));
        $this->assertNull($resolver->resolveFromText('Brasil'));
        $this->assertNull($resolver->resolveFromText('Remoto'));
        $this->assertNull($resolver->resolveFromText(null));
    }

    public function test_city_in_the_title_overrides_a_generic_location(): void
    {
        $resolver = app(VacancyLocationResolver::class);

        $found = $resolver->resolve(
            'Brasil - Rio de Janeiro',
            'Desenvolvedor Backend - Nova Friburgo/RJ'
        );

        $this->assertSame('Nova Friburgo', $found['name']);

        $found = $resolver->resolve(
            'Brasil - Rio de Janeiro',
            'Analista de Suporte - Niteroi/RJ'
        );

        $this->assertSame('Niterói', $found['name']);
    }

    public function test_structured_city_and_state_are_used(): void
    {
        $resolver = app(VacancyLocationResolver::class);

        $found = $resolver->resolve('Brasil', 'Dev', 'Pelotas', 'RS');

        $this->assertSame('Pelotas', $found['name']);
    }

    public function test_structured_state_accepts_abbreviation_and_full_name(): void
    {
        $resolver = app(VacancyLocationResolver::class);

        $cases = [
            ['Niteroi', 'RJ'],
            ['Niteroi', 'Rio de Janeiro'],
            ['Niteroi', 'rio de janeiro'],
        ];

        foreach ($cases as [$city, $state]) {
            $found = $resolver->resolve(
                'Brasil',
                'Desenvolvedor Backend',
                $city,
                $state
            );

            $this->assertSame('Niterói', $found['name'] ?? null);
        }
    }

    public function test_free_text_resolver_accepts_full_state_name_as_hint(): void
    {
        $resolver = app(VacancyLocationResolver::class);

        $found = $resolver->resolveFromText(
            'Niterói',
            'Rio de Janeiro'
        );

        $this->assertSame('Niterói', $found['name'] ?? null);
    }

    public function test_visitor_is_resolved_by_city_name_and_by_nearest_centroid(): void
    {
        $resolver = app(VisitorLocationResolver::class);

        $byName = $resolver->resolve(-22.7081, -42.6150, 'Rio Bonito', 'RJ');
        $this->assertSame('Rio Bonito', $byName['name']);

        // Coordenadas de Nova Friburgo e de Teresópolis, sem nome da cidade.
        $friburgo = $resolver->resolve(-22.2819, -42.5311, null, 'RJ');
        $this->assertSame('Nova Friburgo', $friburgo['name']);

        $nearest = $resolver->resolve(-22.4126, -42.9660, null, 'RJ');
        $this->assertSame('Teresópolis', $nearest['name']);

        // Fora do Brasil, sem UF.
        $this->assertNull($resolver->resolve(40.71, -74.0, null, null));
    }

    public function test_declared_city_must_match_the_coordinates(): void
    {
        $resolver = app(VisitorLocationResolver::class);

        // Coordenadas do Rio de Janeiro dizendo ser Goiânia/GO.
        $this->assertNotSame(
            'Goiânia',
            $resolver->resolve(-22.9068, -43.1729, 'Goiânia', 'GO')['name'] ?? null
        );
        $this->assertNull($resolver->resolve(-22.9068, -43.1729, 'Goiânia', 'GO'));

        // Coordenadas do Rio dizendo ser Porto Alegre/RS.
        $this->assertNull($resolver->resolve(-22.9068, -43.1729, 'Porto Alegre', 'RS'));

        // Sem cidade, com UF forjada: o centroide mais próximo dentro de SP
        // fica longe demais.
        $this->assertNull($resolver->resolve(-22.9068, -43.1729, null, 'GO'));

        // O caso legítimo continua funcionando.
        $this->assertSame(
            'Rio de Janeiro',
            $resolver->resolve(-22.9068, -43.1729, 'Rio de Janeiro', 'RJ')['name']
        );
    }

    public function test_coordinates_outside_brazil_are_never_accepted(): void
    {
        $resolver = app(VisitorLocationResolver::class);

        // Oceano (0, 0) e Paris, mesmo com cidade e UF válidas.
        $this->assertNull($resolver->resolve(0.0, 0.0, 'Porto Alegre', 'RS'));
        $this->assertNull($resolver->resolve(48.85, 2.35, 'Niterói', 'RJ'));
        $this->assertNull($resolver->resolve(48.85, 2.35, null, 'RJ'));
        $this->assertNull($resolver->resolve(48.85, 2.35, null, null));
    }

    public function test_distance_limit_depends_on_the_municipality_having_rm_or_ride(): void
    {
        $resolver = app(VisitorLocationResolver::class);
        $directory = app(MunicipalityDirectory::class);

        $withRegion = $directory->find($this->ibge('Niterói', 'RJ'));
        $withoutRegion = $directory->find($this->ibge('Pelotas', 'RS'));

        $this->assertNotEmpty($withRegion['region_code']);
        $this->assertEmpty($withoutRegion['region_code']);

        // Um ponto a ~150 km a oeste do centroide de cada município.
        $offset = fn (array $m): array => [
            $m['latitude'],
            $m['longitude'] - (150 / (111.32 * cos(deg2rad($m['latitude'])))),
        ];

        // Sem RM/RIDE: 150 km está dentro do limite de 200 km.
        [$lat, $lon] = $offset($withoutRegion);
        $this->assertSame(
            'Pelotas',
            $resolver->resolve($lat, $lon, 'Pelotas', 'RS')['name']
        );

        // Com RM/RIDE: os mesmos 150 km passam do limite de 100 km.
        [$lat, $lon] = $offset($withRegion);
        $found = $resolver->resolve($lat, $lon, 'Niterói', 'RJ');
        $this->assertNotSame('Niterói', $found['name'] ?? null);
    }

    public function test_forged_post_does_not_grant_a_region(): void
    {
        $this->mock(IpLocationResolver::class, function ($mock): void {
            $mock->shouldReceive('resolve')->andReturn(null);
        });

        // Oceano (0, 0) dizendo ser Goiânia/GO.
        $this->postJson(route('welcome.accept'), [
            'terms' => '1',
            'location_status' => 'available',
            'latitude' => 0,
            'longitude' => 0,
            'state_code' => 'GO',
            'city_name' => 'Goiânia',
        ])
            ->assertOk()
            ->assertSessionHas('location_mode', 'limited')
            ->assertSessionMissing('visitor_municipality_ibge')
            ->assertSessionMissing('location_latitude');

        // Coordenadas de Paris dizendo ser Niterói/RJ.
        $this->postJson(route('welcome.accept'), [
            'terms' => '1',
            'location_status' => 'available',
            'latitude' => 48.85,
            'longitude' => 2.35,
            'state_code' => 'RJ',
            'city_name' => 'Niterói',
        ])
            ->assertOk()
            ->assertSessionHas('location_mode', 'limited')
            ->assertSessionMissing('visitor_municipality_ibge');
    }

    public function test_forged_post_never_exposes_local_vacancies(): void
    {
        $this->mock(IpLocationResolver::class, function ($mock): void {
            $mock->shouldReceive('resolve')->andReturn(null);
        });

        $vacancy = Vacancy::create([
            'title' => 'Desenvolvedor PHP Junior Goiania',
            'company' => 'Empresa Goiania',
            'description' => 'Vaga para desenvolvedor junior com Laravel.',
            'location' => 'Goiânia, GO (Presencial)',
            'application_url' => 'https://example.com/candidatar',
            'source' => 'test',
            'external_id' => uniqid('v-', true),
            'original_published_at' => now()->subDays(3),
            'collected_at' => now(),
        ]);

        $this->postJson(route('welcome.accept'), [
            'terms' => '1',
            'location_status' => 'available',
            'latitude' => -22.9068,
            'longitude' => -43.1729,
            'state_code' => 'GO',
            'city_name' => 'Goiânia',
        ])->assertOk();

        $this->get(route('vacancies.index'))
            ->assertOk()
            ->assertDontSee($vacancy->title);

        $this->get(route('vacancies.show', $vacancy))->assertNotFound();
    }

    public function test_honest_post_grants_the_visitors_region(): void
    {
        $this->mock(IpLocationResolver::class, function ($mock): void {
            $mock->shouldReceive('resolve')->andReturn(null);
        });

        $this->postJson(route('welcome.accept'), [
            'terms' => '1',
            'location_status' => 'available',
            'latitude' => -22.7081,
            'longitude' => -42.6150,
            'state_code' => 'RJ',
            'city_name' => 'Rio Bonito',
        ])
            ->assertOk()
            ->assertSessionHas('location_mode', 'available')
            ->assertSessionHas('visitor_municipality_ibge', $this->ibge('Rio Bonito', 'RJ'));
    }

    public function test_vacancies_are_listed_only_for_the_visitors_region(): void
    {
        $make = fn (string $location, string $title): Vacancy => Vacancy::create([
            'title' => $title,
            'company' => 'Empresa ' . $location,
            'description' => 'Vaga para desenvolvedor junior com Laravel.',
            'location' => $location . ' (Presencial)',
            'application_url' => 'https://example.com/candidatar',
            'source' => 'test',
            'external_id' => uniqid('v-', true),
            'original_published_at' => now()->subDays(3),
            'collected_at' => now(),
        ]);

        $niteroi = $make('Niterói, RJ', 'Desenvolvedor PHP Junior Niteroi');
        $nfriburgo = $make('Nova Friburgo, RJ', 'Desenvolvedor PHP Junior Friburgo');
        $paulinia = $make('Paulínia, SP', 'Desenvolvedor PHP Junior Paulinia');

        $session = fn (string $name, string $uf) => $this->verifiedLocationSession(
            $this->ibge($name, $uf)
        );

        $rioBonito = $this->withCookie('ti_vagas_terms_accepted', '1.0')
            ->withSession($session('Rio Bonito', 'RJ'))
            ->get(route('vacancies.index'));

        $rioBonito->assertSee($niteroi->title);
        $rioBonito->assertDontSee($nfriburgo->title);
        $rioBonito->assertDontSee($paulinia->title);

        $teresopolis = $this->withCookie('ti_vagas_terms_accepted', '1.0')
            ->withSession($session('Teresópolis', 'RJ'))
            ->get(route('vacancies.index'));

        $teresopolis->assertSee($nfriburgo->title);
        $teresopolis->assertDontSee($niteroi->title);
        $teresopolis->assertDontSee($paulinia->title);

        $campinas = $this->withCookie('ti_vagas_terms_accepted', '1.0')
            ->withSession($session('Campinas', 'SP'))
            ->get(route('vacancies.index'));

        $campinas->assertSee($paulinia->title);
        $campinas->assertDontSee($niteroi->title);

        $this->withCookie('ti_vagas_terms_accepted', '1.0')
            ->withSession($session('Teresópolis', 'RJ'))
            ->get(route('vacancies.show', $niteroi))
            ->assertNotFound();

        $limited = $this->withCookie('ti_vagas_terms_accepted', '1.0')
            ->withSession(['location_mode' => 'limited'])
            ->get(route('vacancies.index'));

        $limited->assertDontSee($niteroi->title);
    }

    public function test_welcome_stores_the_visitor_municipality_in_the_session(): void
    {
        $response = $this->post(route('welcome.accept'), [
            'terms' => '1',
            'location_status' => 'available',
            'latitude' => -22.7081,
            'longitude' => -42.6150,
            'state_code' => 'RJ',
            'city_name' => 'Rio Bonito',
        ]);

        $response->assertRedirect(route('vacancies.index'));
        $response->assertSessionHas(
            'visitor_municipality_ibge',
            $this->ibge('Rio Bonito', 'RJ')
        );
    }

    public function test_sessions_without_the_integrity_proof_never_release_regional_vacancies(): void
    {
        $vacancy = Vacancy::create([
            'title' => 'Desenvolvedor PHP Junior Niteroi',
            'company' => 'Empresa',
            'description' => 'Vaga para desenvolvedor junior com Laravel.',
            'location' => 'Niterói, RJ (Presencial)',
            'application_url' => 'https://example.com/candidatar',
            'source' => 'test',
            'external_id' => 'unsigned-session',
            'original_published_at' => now()->subDays(3),
            'collected_at' => now(),
        ]);

        // Sessão de versão anterior (só coordenadas e UF) ou escrita por
        // outro caminho: sem selo, não vale como localização verificada.
        $this->withCookie('ti_vagas_terms_accepted', '1.0')
            ->withSession([
                'location_mode' => 'available',
                'location_latitude' => -22.7081,
                'location_longitude' => -42.6150,
                'visitor_state_code' => 'RJ',
            ])
            ->get(route('vacancies.index'))
            ->assertDontSee($vacancy->title);

        $this->withCookie('ti_vagas_terms_accepted', '1.0')
            ->withSession([
                'location_mode' => 'available',
                'visitor_municipality_ibge' => $this->ibge('Niterói', 'RJ'),
            ])
            ->get(route('vacancies.index'))
            ->assertDontSee($vacancy->title);
    }

    public function test_ibge_api_payload_is_applied_and_curated_units_replace_ibge_rms(): void
    {
        // O importador recusa respostas com menos de 5.000 municípios. Os
        // itens de enchimento não têm região imediata e são ignorados.
        $filler = array_map(
            fn (int $id): array => ['id' => 9000000 + $id],
            range(1, 5000)
        );

        Http::fake([
            '*/v1/localidades/municipios' => Http::response(array_merge([
                [
                    'id' => (int) $this->ibge('Teresópolis', 'RJ'),
                    'nome' => 'Teresópolis',
                    'regiao-imediata' => [
                        'id' => 330012,
                        'nome' => 'Teresópolis',
                        'regiao-intermediaria' => [
                            'id' => 3303,
                            'nome' => 'Petrópolis',
                        ],
                    ],
                ],
            ], $filler)),
            '*/v1/localidades/regioes-metropolitanas' => Http::response([
                [
                    'id' => '3301',
                    'nome' => 'Região Metropolitana do Rio de Janeiro',
                    'municipios' => [
                        ['id' => (int) $this->ibge('Teresópolis', 'RJ')],
                    ],
                ],
                [
                    'id' => '4201',
                    'nome' => 'Florianópolis',
                    'municipios' => [
                        ['id' => (int) $this->ibge('Florianópolis', 'SC')],
                        ['id' => (int) $this->ibge('São José', 'SC')],
                    ],
                ],
            ]),
        ]);

        $importer = app(IbgeGeographyImporter::class);
        $result = $importer->importFromIbgeApi();

        $this->assertSame(1, $result['municipalities']);
        // A RM do Rio da API foi ignorada: o ajuste local prevalece.
        $this->assertSame(2, $result['metropolitan']);

        $this->assertSame([], $importer->applyCuratedUnits());

        $teresopolis = Municipality::query()
            ->where('ibge_code', $this->ibge('Teresópolis', 'RJ'))
            ->first();

        $this->assertSame('3303', $teresopolis->intermediate_region_code);
        $this->assertNull($teresopolis->region_code);

        $florianopolis = Municipality::query()
            ->where('ibge_code', $this->ibge('Florianópolis', 'SC'))
            ->first();

        $this->assertSame('RM-4201', $florianopolis->region_code);
    }
}
