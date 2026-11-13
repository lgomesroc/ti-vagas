<?php

namespace Tests\Feature;

use App\Models\Vacancy;
use App\Services\Geo\MunicipalityDirectory;
use App\Services\Geo\Security\LocationGuard;
use App\Services\Geo\Security\LocationProof;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\BuildsVerifiedLocationSession;
use Tests\Concerns\SeedsGeography;
use Tests\TestCase;

/**
 * Proteção contra falsificação de localização.
 *
 * Estes testes cobrem o que o servidor consegue verificar. Nenhum deles
 * prova que o GPS é verdadeiro: simular uma posição plausível continua
 * possível, e é isso que o README documenta.
 */
class LocationSpoofingProtectionTest extends TestCase
{
    use RefreshDatabase, SeedsGeography, BuildsVerifiedLocationSession;

    // Posições a ~500 m do centro de cada cidade (não são o centroide).
    private const NITEROI = [-22.8791, -43.1042];
    private const RIO = [-22.9112, -43.1685];
    private const PORTO_ALEGRE = [-30.0412, -51.2231];

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame([], $this->seedGeography());
    }

    private function vacancy(string $location, string $title): Vacancy
    {
        return Vacancy::create([
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
    }

    /**
     * @return array<string, mixed>
     */
    private function niteroiSession(array $extra = []): array
    {
        return [
            'terms_accepted_version' => '1.0',
            ...$this->verifiedLocationSession($this->ibge('Niterói', 'RJ'), self::NITEROI),
            ...$extra,
        ];
    }

    private function refresh(array $session, array $coords, float $accuracy = 50.0, array $extra = [])
    {
        return $this->withSession($session)->postJson(route('location.refresh'), [
            'latitude' => $coords[0],
            'longitude' => $coords[1],
            'accuracy' => $accuracy,
            ...$extra,
        ]);
    }

    // ---------------------------------------------------------------------
    // URL, corpo e cookies não escolhem a região
    // ---------------------------------------------------------------------

    public function test_url_parameters_cannot_release_vacancies_from_another_region(): void
    {
        $niteroi = $this->vacancy('Niterói, RJ', 'Desenvolvedor PHP Junior Niteroi');
        $goiania = $this->vacancy('Goiânia, GO', 'Desenvolvedor PHP Junior Goiania');

        $query = http_build_query([
            'uf' => 'GO',
            'state_code' => 'GO',
            'city' => 'Goiânia',
            'cidade' => 'Goiânia',
            'municipio' => 'Goiânia',
            'ibge' => $this->ibge('Goiânia', 'GO'),
            'region' => 'goiania',
            'lat' => -16.6869,
            'lng' => -49.2648,
            'latitude' => -16.6869,
            'longitude' => -49.2648,
            'location_mode' => 'available',
        ]);

        $this->withSession($this->niteroiSession())
            ->get('/?' . $query)
            ->assertOk()
            ->assertSee($niteroi->title)
            ->assertDontSee($goiania->title);

        $this->withSession($this->niteroiSession())
            ->get(route('vacancies.show', $goiania) . '?' . $query)
            ->assertNotFound();
    }

    public function test_url_parameters_do_not_give_regional_vacancies_to_a_visitor_without_location(): void
    {
        $niteroi = $this->vacancy('Niterói, RJ', 'Desenvolvedor PHP Junior Niteroi');

        $this->withSession([
            'terms_accepted_version' => '1.0',
            'location_mode' => 'limited',
        ])
            ->get('/?' . http_build_query([
                'uf' => 'RJ',
                'city' => 'Niterói',
                'ibge' => $this->ibge('Niterói', 'RJ'),
                'lat' => self::NITEROI[0],
                'lng' => self::NITEROI[1],
                'location_mode' => 'available',
            ]))
            ->assertOk()
            ->assertDontSee($niteroi->title);
    }

    public function test_location_parameters_are_not_forwarded_to_the_pagination_links(): void
    {
        for ($i = 0; $i < 14; $i++) {
            $this->vacancy('Niterói, RJ', 'Desenvolvedor PHP Junior ' . $i);
        }

        $response = $this->withSession($this->niteroiSession())
            ->get('/?uf=GO&lat=-16.68&city=Goiania');

        $response->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString('page=2', $html);
        $this->assertStringNotContainsString('uf=GO', $html);
        $this->assertStringNotContainsString('lat=-16.68', $html);
    }

    public function test_cookies_cannot_choose_the_region(): void
    {
        $niteroi = $this->vacancy('Niterói, RJ', 'Desenvolvedor PHP Junior Niteroi');

        $this->withUnencryptedCookies([
            'location_mode' => 'available',
            'visitor_municipality_ibge' => $this->ibge('Niterói', 'RJ'),
            'visitor_state_code' => 'RJ',
            'uf' => 'RJ',
        ])->withSession([
            'terms_accepted_version' => '1.0',
            'location_mode' => 'limited',
        ])->get('/')
            ->assertOk()
            ->assertDontSee($niteroi->title);
    }

    public function test_tampering_attempts_are_logged_without_coordinates_or_identifiers(): void
    {
        Log::spy();

        $this->withSession($this->niteroiSession())
            ->get('/?uf=GO&lat=-16.6869&lng=-49.2648')
            ->assertOk();

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context = []): bool {
                $payload = json_encode($context);

                return $message === 'location_guard.suspicious'
                    && $context['reasons'] === ['parameter_tampering']
                    && !str_contains($payload, '16.6869')
                    && !str_contains($payload, '49.2648')
                    && !str_contains($payload, '127.0.0.1');
            })
            ->once();
    }

    public function test_client_supplied_city_and_state_in_the_refresh_body_are_removed_and_recorded(): void
    {
        $this->refresh(
            $this->niteroiSession(),
            self::PORTO_ALEGRE,
            50.0,
            ['city_name' => 'Goiânia', 'state_code' => 'GO', 'ibge' => $this->ibge('Goiânia', 'GO')]
        )
            ->assertOk()
            ->assertJson(['updated' => true])
            // Região vem das coordenadas (Porto Alegre), nunca do que o navegador declarou.
            ->assertSessionHas('visitor_municipality_ibge', $this->ibge('Porto Alegre', 'RS'))
            ->assertSessionHas(LocationGuard::SESSION_KEY . '.score', 1.0);
    }

    // ---------------------------------------------------------------------
    // Integridade da sessão
    // ---------------------------------------------------------------------

    public function test_forged_session_location_never_releases_regional_vacancies(): void
    {
        $niteroi = $this->vacancy('Niterói, RJ', 'Desenvolvedor PHP Junior Niteroi');
        $goiania = $this->vacancy('Goiânia, GO', 'Desenvolvedor PHP Junior Goiania');

        // Controle positivo: sessão gravada pelo servidor funciona.
        $this->withSession($this->niteroiSession())
            ->get('/')
            ->assertSee($niteroi->title)
            ->assertDontSee($goiania->title);

        $forged = $this->niteroiSession();

        // Município trocado depois do selo.
        $this->withSession([...$forged, 'visitor_municipality_ibge' => $this->ibge('Goiânia', 'GO')])
            ->get('/')
            ->assertDontSee($goiania->title)
            ->assertDontSee($niteroi->title);

        // Selo inventado.
        $this->withSession([...$forged, LocationProof::SESSION_KEY => str_repeat('a', 64)])
            ->get('/')
            ->assertDontSee($niteroi->title);

        // Coordenadas trocadas depois do selo.
        $this->withSession([...$forged, 'location_latitude' => -16.6869, 'location_longitude' => -49.2648])
            ->get('/')
            ->assertDontSee($niteroi->title)
            ->assertDontSee($goiania->title);

        // Sem selo nenhum (a sessão persiste entre as requisições do teste,
        // então a chave é zerada em vez de apenas omitida).
        $this->withSession([...$forged, LocationProof::SESSION_KEY => null])
            ->get('/')
            ->assertDontSee($niteroi->title);
    }

    // ---------------------------------------------------------------------
    // Coordenadas suspeitas
    // ---------------------------------------------------------------------

    public function test_impossible_travel_is_rejected_and_keeps_the_current_region(): void
    {
        // 1ª posição (mesma região): aceita e vira a referência de velocidade.
        $this->refresh($this->niteroiSession(), self::RIO)
            ->assertOk()
            ->assertExactJson(['updated' => false, 'reason' => 'unchanged']);

        // ~1.100 km depois, no mesmo instante.
        $this->refresh($this->niteroiSession(), self::PORTO_ALEGRE)
            ->assertOk()
            ->assertExactJson(['updated' => false, 'reason' => 'unverified'])
            ->assertSessionHas('visitor_municipality_ibge', $this->ibge('Niterói', 'RJ'));
    }

    public function test_coordinates_equal_to_a_public_municipality_centroid_are_rejected(): void
    {
        // O CSV com os centroides é público no repositório: copiá-los é o
        // jeito mais simples de forjar uma cidade.
        $porto = app(MunicipalityDirectory::class)->find($this->ibge('Porto Alegre', 'RS'));

        $this->refresh(
            $this->niteroiSession(),
            [(float) $porto['latitude'], (float) $porto['longitude']]
        )
            ->assertOk()
            ->assertExactJson(['updated' => false, 'reason' => 'unverified'])
            ->assertSessionHas('visitor_municipality_ibge', $this->ibge('Niterói', 'RJ'));
    }

    public function test_round_coordinates_with_perfect_accuracy_are_rejected(): void
    {
        $this->refresh($this->niteroiSession(), [-30.0, -51.2], 0.0)
            ->assertOk()
            ->assertExactJson(['updated' => false, 'reason' => 'unverified'])
            ->assertSessionHas('visitor_municipality_ibge', $this->ibge('Niterói', 'RJ'));
    }

    public function test_a_single_weak_signal_is_recorded_but_does_not_block_a_real_move(): void
    {
        // Hora do GPS 1 h atrás: sinal fraco (peso 2 < 4).
        $this->refresh(
            $this->niteroiSession(),
            self::PORTO_ALEGRE,
            50.0,
            ['captured_at' => (int) round(microtime(true) * 1000) - 3_600_000]
        )
            ->assertOk()
            ->assertJson(['updated' => true])
            ->assertSessionHas('visitor_municipality_ibge', $this->ibge('Porto Alegre', 'RS'))
            ->assertSessionHas(LocationGuard::SESSION_KEY . '.score', 2.0);
    }

    public function test_a_fresh_gps_timestamp_is_accepted(): void
    {
        $this->refresh(
            $this->niteroiSession(),
            self::PORTO_ALEGRE,
            50.0,
            ['captured_at' => (int) round(microtime(true) * 1000) - 900]
        )
            ->assertOk()
            ->assertJson(['updated' => true])
            ->assertSessionMissing(LocationGuard::SESSION_KEY . '.score');
    }

    public function test_malformed_payloads_are_recorded_as_suspicious(): void
    {
        $this->withSession($this->niteroiSession())
            ->postJson(route('location.refresh'), [
                'latitude' => 'drop table',
                'longitude' => ['x'],
                'accuracy' => -1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['latitude', 'longitude', 'accuracy'])
            ->assertSessionHas(LocationGuard::SESSION_KEY . '.score', 2.0);
    }

    public function test_coordinates_that_resolve_to_no_municipality_are_recorded_but_change_nothing(): void
    {
        $this->refresh($this->niteroiSession(), [48.8566, 2.3522])
            ->assertOk()
            ->assertExactJson(['updated' => false, 'reason' => 'unresolved'])
            ->assertSessionHas('visitor_municipality_ibge', $this->ibge('Niterói', 'RJ'))
            ->assertSessionHas(LocationGuard::SESSION_KEY . '.score', 1.0);
    }

    // ---------------------------------------------------------------------
    // Reincidência e troca excessiva de região
    // ---------------------------------------------------------------------

    public function test_repeated_suspicious_attempts_freeze_the_current_region(): void
    {
        $session = $this->niteroiSession();

        // Duas tentativas de peso 4 atingem o limite de bloqueio (8).
        $this->refresh($session, [-30.0, -51.2], 0.0)->assertJson(['reason' => 'unverified']);
        $this->refresh($session, [-30.0, -51.2], 0.0)->assertJson(['reason' => 'unverified']);

        $this->assertNotNull(session(LocationGuard::SESSION_KEY . '.locked_until'));

        // Mesmo uma posição perfeitamente plausível fica congelada.
        $this->refresh($session, self::PORTO_ALEGRE)
            ->assertOk()
            ->assertExactJson(['updated' => false, 'reason' => 'unverified'])
            ->assertSessionHas('visitor_municipality_ibge', $this->ibge('Niterói', 'RJ'));

        // E a região anterior continua valendo para listar vagas.
        $niteroi = $this->vacancy('Niterói, RJ', 'Desenvolvedor PHP Junior Niteroi');

        $this->get('/')->assertSee($niteroi->title);
    }

    public function test_the_freeze_ends_after_the_lock_period(): void
    {
        $session = $this->niteroiSession([
            LocationGuard::SESSION_KEY => [
                'score' => 9.0,
                'score_at' => time() - 7200,
                'locked_until' => time() - 60,
            ],
        ]);

        $this->refresh($session, self::PORTO_ALEGRE)
            ->assertOk()
            ->assertJson(['updated' => true]);
    }

    public function test_too_many_region_changes_in_an_hour_are_refused(): void
    {
        $session = $this->niteroiSession([
            LocationGuard::SESSION_KEY => [
                'region_changes' => [time() - 30, time() - 20, time() - 10],
            ],
        ]);

        $this->refresh($session, self::PORTO_ALEGRE)
            ->assertOk()
            ->assertExactJson(['updated' => false, 'reason' => 'unverified'])
            ->assertSessionHas('visitor_municipality_ibge', $this->ibge('Niterói', 'RJ'));
    }

    public function test_old_region_changes_do_not_count_against_the_limit(): void
    {
        $session = $this->niteroiSession([
            LocationGuard::SESSION_KEY => [
                'region_changes' => [time() - 7200, time() - 7300, time() - 7400],
            ],
        ]);

        $this->refresh($session, self::PORTO_ALEGRE)
            ->assertOk()
            ->assertJson(['updated' => true]);
    }

    public function test_the_region_change_limit_also_applies_per_ip_across_sessions(): void
    {
        config(['location_guard.max_region_changes_per_ip_hour' => 2]);

        // Cada "sessão nova" (cookies apagados) troca de região uma vez.
        $this->refresh($this->niteroiSession(), self::PORTO_ALEGRE)->assertJson(['updated' => true]);
        $this->refresh($this->niteroiSession(), self::PORTO_ALEGRE)->assertJson(['updated' => true]);

        $this->refresh($this->niteroiSession(), self::PORTO_ALEGRE)
            ->assertExactJson(['updated' => false, 'reason' => 'unverified']);
    }

    public function test_the_guard_can_be_disabled_by_configuration(): void
    {
        config(['location_guard.enabled' => false]);

        $porto = app(MunicipalityDirectory::class)->find($this->ibge('Porto Alegre', 'RS'));

        $this->refresh(
            $this->niteroiSession(),
            [(float) $porto['latitude'], (float) $porto['longitude']]
        )
            ->assertOk()
            ->assertJson(['updated' => true]);
    }

    public function test_a_valid_update_issues_a_proof_that_verifies(): void
    {
        $this->refresh($this->niteroiSession(), self::PORTO_ALEGRE)
            ->assertJson(['updated' => true]);

        $this->assertTrue(app(LocationProof::class)->verify(session()->driver()));
    }
}
