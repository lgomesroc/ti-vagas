<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureWelcomeAccepted;
use App\Models\Vacancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsVerifiedLocationSession;
use Tests\Concerns\SeedsGeography;
use Tests\TestCase;

/**
 * O que o visitante escolheu no navegador vale nas visitas seguintes:
 * permissão concedida mostra também vagas presenciais e híbridas da região;
 * sem permissão (negada, bloqueada ou revogada) só vagas remotas; a região
 * nunca é adivinhada.
 */
class LocationPermissionFlowTest extends TestCase
{
    use RefreshDatabase, SeedsGeography, BuildsVerifiedLocationSession;

    // A cerca de 500 m do centro: GPS real nunca cai exatamente no centroide.
    private const NITEROI = [-22.8791, -43.1042];

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame([], $this->seedGeography());
    }

    /**
     * Visitante que já aceitou os termos em outra visita: só o cookie de longa
     * duração sobrou, a sessão é nova.
     */
    private function returningVisitor(): static
    {
        // withCredentials: sem isso o Laravel não envia cookies nas
        // requisições JSON dos testes (o script do navegador usa fetch com
        // `credentials: 'same-origin'`, que envia).
        return $this->withCookie(
            EnsureWelcomeAccepted::TERMS_COOKIE,
            EnsureWelcomeAccepted::TERMS_VERSION
        )->withCredentials();
    }

    /**
     * @return array<string, mixed>
     */
    private function availableSession(): array
    {
        return [
            'terms_accepted_version' => EnsureWelcomeAccepted::TERMS_VERSION,
            ...$this->verifiedLocationSession($this->ibge('Niterói', 'RJ'), self::NITEROI),
        ];
    }

    private function vacancy(string $title, string $location): Vacancy
    {
        return Vacancy::create([
            'title' => $title,
            'company' => 'Empresa ' . $title,
            'description' => 'Vaga para desenvolvedor junior com Laravel.',
            'location' => $location,
            'application_url' => 'https://example.com/candidatar',
            'source' => 'test',
            'external_id' => uniqid('v-', true),
            'original_published_at' => now()->subDays(3),
            'collected_at' => now(),
        ]);
    }

    private function refresh(array $coords = self::NITEROI, float $accuracy = 40.0)
    {
        return $this->postJson(route('location.refresh'), [
            'latitude' => $coords[0],
            'longitude' => $coords[1],
            'accuracy' => $accuracy,
        ]);
    }

    // --- Segunda visita em diante --------------------------------------

    public function test_returning_visitor_with_a_fresh_session_still_gets_the_location_script(): void
    {
        // Antes: sem `location_mode` na sessão nova, o script nem era incluído
        // e o visitante ficava preso às vagas remotas, mesmo com a permissão
        // já concedida no navegador.
        $this->returningVisitor()
            ->get(route('vacancies.index'))
            ->assertOk()
            ->assertSessionHas('location_mode', 'limited')
            ->assertSee('id="location-refresh"', false)
            ->assertSee('var LOCATION_MODE = "limited"', false);
    }

    public function test_returning_visitor_with_a_fresh_session_can_receive_the_region_without_accepting_terms_again(): void
    {
        $local = $this->vacancy('Desenvolvedor PHP Junior Niteroi', 'Niterói, RJ (Presencial)');
        $remote = $this->vacancy('Desenvolvedor PHP Junior Remoto', 'Brasil (Remoto)');

        // Visita com a permissão já concedida no navegador, mas sessão nova.
        $this->returningVisitor()
            ->get(route('vacancies.index'))
            ->assertOk()
            ->assertSee($remote->title)
            ->assertDontSee($local->title);

        // O script envia a posição e o servidor a valida (antes: "disabled").
        $this->refresh()
            ->assertOk()
            ->assertJson(['updated' => true])
            ->assertSessionHas('location_mode', 'available')
            ->assertSessionHas('visitor_municipality_ibge', $this->ibge('Niterói', 'RJ'));

        $this->get(route('vacancies.index'))
            ->assertOk()
            ->assertSee($local->title)
            ->assertSee($remote->title);
    }

    public function test_returning_visitor_keeps_an_available_region_already_in_the_session(): void
    {
        $local = $this->vacancy('Desenvolvedor PHP Junior Niteroi', 'Niterói, RJ (Presencial)');

        $this->returningVisitor()
            ->withSession($this->availableSession())
            ->get(route('vacancies.index'))
            ->assertOk()
            ->assertSessionHas('location_mode', 'available')
            ->assertSee($local->title);
    }

    public function test_a_fresh_session_never_starts_with_a_region(): void
    {
        $local = $this->vacancy('Desenvolvedor PHP Junior Niteroi', 'Niterói, RJ (Presencial)');

        $this->returningVisitor()
            ->get(route('vacancies.index'))
            ->assertSessionMissing('visitor_municipality_ibge')
            ->assertSessionMissing('location_latitude')
            ->assertDontSee($local->title);
    }

    // --- Permissão bloqueada ou revogada -------------------------------

    public function test_disabling_returns_to_remote_only_and_drops_the_region(): void
    {
        $local = $this->vacancy('Desenvolvedor PHP Junior Niteroi', 'Niterói, RJ (Presencial)');
        $remote = $this->vacancy('Desenvolvedor PHP Junior Remoto', 'Brasil (Remoto)');

        $this->withSession($this->availableSession())
            ->get(route('vacancies.index'))
            ->assertSee($local->title);

        $this->postJson(route('location.disable'))
            ->assertOk()
            ->assertExactJson(['disabled' => true, 'changed' => true])
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSessionHas('location_mode', 'limited')
            ->assertSessionMissing('visitor_municipality_ibge')
            ->assertSessionMissing('visitor_state_code')
            ->assertSessionMissing('location_latitude')
            ->assertSessionMissing('location_longitude')
            ->assertSessionMissing('visitor_location_label')
            ->assertSessionMissing('location_proof');

        $this->get(route('vacancies.index'))
            ->assertOk()
            ->assertSee($remote->title)
            ->assertDontSee($local->title);

        $this->get(route('vacancies.show', $local))->assertNotFound();
    }

    public function test_disabling_keeps_the_anti_fraud_history(): void
    {
        // Revogar a permissão e pedir de novo não pode apagar uma trava.
        $session = $this->availableSession();
        $session['location_guard'] = [
            'score' => 9.0,
            'score_at' => time(),
            'locked_until' => time() + 1800,
        ];

        $this->withSession($session)
            ->postJson(route('location.disable'))
            ->assertOk()
            ->assertSessionHas('location_mode', 'limited')
            ->assertSessionHas('location_guard.locked_until');

        $this->refresh()
            ->assertOk()
            ->assertExactJson(['updated' => false, 'reason' => 'unverified'])
            ->assertSessionHas('location_mode', 'limited');
    }

    public function test_disabling_a_limited_session_reports_no_change(): void
    {
        $this->returningVisitor()
            ->postJson(route('location.disable'))
            ->assertOk()
            ->assertExactJson(['disabled' => true, 'changed' => false])
            ->assertSessionHas('location_mode', 'limited');
    }

    public function test_asking_again_after_a_revocation_restores_the_regional_vacancies(): void
    {
        $local = $this->vacancy('Desenvolvedor PHP Junior Niteroi', 'Niterói, RJ (Presencial)');

        $this->withSession($this->availableSession())
            ->postJson(route('location.disable'))
            ->assertJson(['changed' => true]);

        $this->get(route('vacancies.index'))->assertDontSee($local->title);

        // O visitante permite de novo no aviso do navegador.
        $this->refresh()
            ->assertOk()
            ->assertJson(['updated' => true])
            ->assertSessionHas('location_mode', 'available');

        $this->get(route('vacancies.index'))->assertSee($local->title);
    }

    public function test_disable_endpoint_requires_accepted_terms(): void
    {
        $this->postJson(route('location.disable'))
            ->assertRedirect(route('welcome'));
    }

    public function test_disable_endpoint_is_rate_limited(): void
    {
        $this->returningVisitor();

        for ($i = 0; $i < 12; $i++) {
            $this->postJson(route('location.disable'))->assertOk();
        }

        $this->postJson(route('location.disable'))->assertStatus(429);
    }

    // --- Sem adivinhar a localização -----------------------------------

    public function test_the_listing_never_shows_a_location_guess(): void
    {
        // Sessão antiga, de quando a estimativa por IP era gravada.
        $this->returningVisitor()
            ->withSession(['ip_location_label' => 'Nova Iguaçu - RJ'])
            ->get(route('vacancies.index'))
            ->assertOk()
            ->assertDontSee('Nova Iguaçu')
            ->assertDontSee('pelo IP')
            ->assertSee('Localização não ativada')
            ->assertSee('Não tentamos adivinhar')
            ->assertSee('data-location-request', false);
    }

    public function test_the_activation_notice_is_hidden_once_the_region_is_known(): void
    {
        $this->withSession($this->availableSession())
            ->get(route('vacancies.index'))
            ->assertOk()
            ->assertDontSee('Localização não ativada')
            ->assertDontSee('Não tentamos adivinhar');
    }

    public function test_an_ip_address_alone_never_releases_local_vacancies(): void
    {
        $local = $this->vacancy('Desenvolvedor PHP Junior Niteroi', 'Niterói, RJ (Presencial)');

        $this->returningVisitor()
            ->get(route('vacancies.index'), ['X-Forwarded-For' => '200.152.38.1'])
            ->assertOk()
            ->assertSessionHas('location_mode', 'limited')
            ->assertDontSee($local->title);
    }
}
