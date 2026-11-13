<?php

namespace Tests\Feature;

use App\Models\Vacancy;
use App\Services\Geo\VisitorLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsVerifiedLocationSession;
use Tests\Concerns\SeedsGeography;
use Tests\TestCase;

class LocationRefreshTest extends TestCase
{
    use RefreshDatabase, SeedsGeography, BuildsVerifiedLocationSession;

    // Posições a cerca de 500 m do centro de cada cidade. Não podem ser o
    // centroide do IBGE: GPS real nunca cai exatamente nesse ponto, e o guard
    // recusa coordenadas que coincidem com ele (ver LocationSpoofingGuardTest).
    private const NITEROI = [-22.8791, -43.1042];
    private const RIO = [-22.9112, -43.1685];
    private const PORTO_ALEGRE = [-30.0412, -51.2231];
    private const PARIS = [48.85, 2.35];

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame([], $this->seedGeography());
    }

    private function sessionData(string $mode = 'available', array $coords = self::NITEROI, string $city = 'Niterói', string $uf = 'RJ'): array
    {
        return [
            'terms_accepted_version' => '1.0',
            ...$this->verifiedLocationSession($this->ibge($city, $uf), $coords),
            'location_mode' => $mode,
        ];
    }

    private function refresh(array $session, array $coords, float $accuracy = 50.0)
    {
        return $this->withSession($session)->postJson(route('location.refresh'), [
            'latitude' => $coords[0],
            'longitude' => $coords[1],
            'accuracy' => $accuracy,
        ]);
    }

    public function test_it_requires_accepted_terms(): void
    {
        $this->postJson(route('location.refresh'), [
            'latitude' => -22.9,
            'longitude' => -43.1,
            'accuracy' => 10,
        ])->assertRedirect(route('welcome'));
    }

    public function test_it_validates_the_payload(): void
    {
        $this->withSession($this->sessionData())
            ->postJson(route('location.refresh'), ['latitude' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['latitude', 'longitude', 'accuracy']);
    }

    public function test_changing_region_updates_the_session_and_the_listed_vacancies_without_new_terms(): void
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
        $poa = $make('Porto Alegre, RS', 'Desenvolvedor PHP Junior Porto Alegre');

        $this->withSession($this->sessionData())
            ->get(route('vacancies.index'))
            ->assertSee($niteroi->title)
            ->assertDontSee($poa->title);

        $this->refresh($this->sessionData(), self::PORTO_ALEGRE)
            ->assertOk()
            ->assertJson(['updated' => true])
            ->assertSessionHas('visitor_municipality_ibge', $this->ibge('Porto Alegre', 'RS'))
            ->assertSessionHas('location_mode', 'available')
            ->assertHeader('Cache-Control', 'no-store, private');

        // A mesma sessão passa a ver as vagas da nova região, sem aceitar os termos de novo.
        $this->get(route('vacancies.index'))
            ->assertOk()
            ->assertSee($poa->title)
            ->assertDontSee($niteroi->title);
    }

    public function test_same_region_does_not_touch_the_session(): void
    {
        // Rio de Janeiro e Niterói estão na mesma RM.
        $session = $this->sessionData();

        $this->refresh($session, self::RIO)
            ->assertOk()
            ->assertExactJson(['updated' => false, 'reason' => 'unchanged'])
            ->assertSessionHas('visitor_municipality_ibge', $this->ibge('Niterói', 'RJ'))
            ->assertSessionHas('location_latitude', self::NITEROI[0])
            ->assertSessionHas('location_longitude', self::NITEROI[1]);
    }

    public function test_imprecise_coordinates_do_not_change_the_region(): void
    {
        $this->refresh($this->sessionData(), self::PORTO_ALEGRE, VisitorLocationResolver::MAX_ACCURACY_METERS + 1)
            ->assertOk()
            ->assertExactJson(['updated' => false, 'reason' => 'inaccurate'])
            ->assertSessionHas('visitor_municipality_ibge', $this->ibge('Niterói', 'RJ'));
    }

    public function test_accuracy_exactly_at_the_limit_is_accepted(): void
    {
        $this->refresh($this->sessionData(), self::PORTO_ALEGRE, VisitorLocationResolver::MAX_ACCURACY_METERS)
            ->assertOk()
            ->assertJson(['updated' => true]);
    }

    public function test_coordinates_outside_brazil_never_change_the_region(): void
    {
        $this->refresh($this->sessionData(), self::PARIS)
            ->assertOk()
            ->assertExactJson(['updated' => false, 'reason' => 'unresolved'])
            ->assertSessionHas('visitor_municipality_ibge', $this->ibge('Niterói', 'RJ'));

        $this->refresh($this->sessionData(), [0.0, 0.0])
            ->assertOk()
            ->assertJson(['updated' => false]);
    }

    public function test_first_valid_location_promotes_limited_session_to_available(): void
    {
        $this->refresh($this->sessionData('limited'), self::PORTO_ALEGRE)
            ->assertOk()
            ->assertJson(['updated' => true])
            ->assertSessionHas('location_mode', 'available')
            ->assertSessionHas('visitor_municipality_ibge', $this->ibge('Porto Alegre', 'RS'));
    }

    public function test_invalid_location_keeps_limited_session_limited(): void
    {
        $this->refresh($this->sessionData('limited'), self::PARIS)
            ->assertOk()
            ->assertExactJson(['updated' => false, 'reason' => 'unresolved'])
            ->assertSessionHas('location_mode', 'limited');

        $this->refresh($this->sessionData('limited'), self::PORTO_ALEGRE, VisitorLocationResolver::MAX_ACCURACY_METERS + 1)
            ->assertOk()
            ->assertExactJson(['updated' => false, 'reason' => 'inaccurate'])
            ->assertSessionHas('location_mode', 'limited');
    }

    public function test_a_different_region_close_to_the_reference_position_is_ignored(): void
    {
        // A sessão guarda uma posição em Porto Alegre, mas o município é Niterói
        // (região diferente). Coordenadas a poucos metros da posição guardada
        // não devem trocar a região: é oscilação, não deslocamento.
        $session = $this->sessionData('available', self::PORTO_ALEGRE);

        $this->refresh($session, [self::PORTO_ALEGRE[0] + 0.005, self::PORTO_ALEGRE[1]])
            ->assertOk()
            ->assertExactJson(['updated' => false, 'reason' => 'nearby'])
            ->assertSessionHas('visitor_municipality_ibge', $this->ibge('Niterói', 'RJ'));
    }

    public function test_client_supplied_city_and_state_are_ignored(): void
    {
        // Coordenadas de Paris dizendo ser Goiânia: nada muda.
        $this->withSession($this->sessionData())
            ->postJson(route('location.refresh'), [
                'latitude' => self::PARIS[0],
                'longitude' => self::PARIS[1],
                'accuracy' => 10,
                'city_name' => 'Goiânia',
                'state_code' => 'GO',
            ])
            ->assertOk()
            ->assertJson(['updated' => false])
            ->assertSessionHas('visitor_municipality_ibge', $this->ibge('Niterói', 'RJ'));
    }

    public function test_the_endpoint_is_rate_limited(): void
    {
        $session = $this->sessionData();

        for ($i = 0; $i < 6; $i++) {
            $this->refresh($session, self::RIO)->assertOk();
        }

        $this->refresh($session, self::RIO)->assertStatus(429);
    }

    public function test_the_refresh_script_is_included_only_for_visitors_with_location_enabled(): void
    {
        $this->withSession($this->sessionData('available'))
            ->get(route('vacancies.index'))
            ->assertSee('id="location-refresh"', false)
            ->assertSee(json_encode(route('location.refresh')), false)
            ->assertSee('getCurrentPosition(', false)
            // Nenhuma chamada de rastreamento contínuo (o comentário não conta).
            ->assertDontSee('watchPosition(', false);

        $this->withSession($this->sessionData('limited'))
            ->get(route('vacancies.index'))
            ->assertSee('id="location-refresh"', false)
            ->assertSee('var LOCATION_MODE = "limited"', false);

        $this->withSession($this->sessionData('available'))
            ->get(route('welcome'))
            ->assertDontSee('id="location-refresh"', false);
    }
}
