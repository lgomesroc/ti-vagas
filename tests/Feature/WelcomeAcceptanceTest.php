<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureWelcomeAccepted;
use App\Services\Geo\IpLocationResolver;
use App\Services\Geo\VisitorLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WelcomeAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_declining_browser_location_keeps_only_national_remote_scope_and_uses_ip_estimate(): void
    {
        $this->mock(IpLocationResolver::class, function ($mock): void {
            $mock->shouldReceive('resolve')
                ->once()
                ->andReturn([
                    'city' => 'Nova Iguaçu',
                    'state_code' => 'RJ',
                    'label' => 'Nova Iguaçu - RJ',
                ]);
        });

        $response = $this->post(route('welcome.accept'), [
            'terms' => '1',
            'location_status' => 'limited',
        ]);

        $response->assertRedirect(route('vacancies.index'));
        $response->assertSessionHas(
            'terms_accepted_version',
            EnsureWelcomeAccepted::TERMS_VERSION
        );
        $response->assertSessionHas('location_mode', 'limited');
        $response->assertSessionHas('ip_location_label', 'Nova Iguaçu - RJ');
    }

    public function test_failed_precise_location_does_not_return_to_welcome_and_falls_back_to_limited_mode(): void
    {
        $this->mock(VisitorLocationResolver::class, function ($mock): void {
            $mock->shouldReceive('resolve')
                ->once()
                ->andReturn(null);
        });

        $this->mock(IpLocationResolver::class, function ($mock): void {
            $mock->shouldReceive('resolve')
                ->once()
                ->andReturn(null);
        });

        $response = $this->post(route('welcome.accept'), [
            'terms' => '1',
            'location_status' => 'available',
            'latitude' => -22.75,
            'longitude' => -43.45,
            'state_code' => 'RJ',
            'city_name' => 'Cidade não cadastrada',
        ]);

        $response->assertRedirect(route('vacancies.index'));
        $response->assertSessionHas('location_mode', 'limited');
        $response->assertSessionHas(
            'terms_accepted_version',
            EnsureWelcomeAccepted::TERMS_VERSION
        );
    }

    public function test_session_acceptance_is_enough_to_pass_welcome_middleware_without_cookie(): void
    {
        $this->withSession([
            'terms_accepted_version' => EnsureWelcomeAccepted::TERMS_VERSION,
            'location_mode' => 'limited',
        ])->get(route('vacancies.index'))->assertOk();
    }

    public function test_cookie_acceptance_is_enough_to_pass_welcome_middleware_without_session(): void
    {
        $this->withCookie(
            EnsureWelcomeAccepted::TERMS_COOKIE,
            EnsureWelcomeAccepted::TERMS_VERSION
        )->get(route('vacancies.index'))->assertOk();
    }

    public function test_visitor_without_acceptance_is_sent_to_welcome(): void
    {
        $this->get(route('vacancies.index'))
            ->assertRedirect(route('welcome'));
    }

    public function test_accepting_with_precise_location_releases_site_and_sets_terms_cookie(): void
    {
        $this->mock(VisitorLocationResolver::class, function ($mock): void {
            $mock->shouldReceive('resolve')
                ->once()
                ->andReturn([
                    'ibge' => '3304557',
                    'name' => 'Rio de Janeiro',
                    'uf' => 'RJ',
                ]);
        });

        $response = $this->post(route('welcome.accept'), [
            'terms' => '1',
            'location_status' => 'available',
            'latitude' => -22.9,
            'longitude' => -43.2,
            'state_code' => 'RJ',
            'city_name' => 'Rio de Janeiro',
        ]);

        $response->assertRedirect(route('vacancies.index'));
        $response->assertSessionHas('location_mode', 'available');
        $response->assertSessionHas(
            'terms_accepted_version',
            EnsureWelcomeAccepted::TERMS_VERSION
        );
        $response->assertCookie(EnsureWelcomeAccepted::TERMS_COOKIE);
    }

    public function test_declining_location_also_sets_terms_cookie(): void
    {
        $this->mock(IpLocationResolver::class, function ($mock): void {
            $mock->shouldReceive('resolve')->once()->andReturn(null);
        });

        $this->post(route('welcome.accept'), [
            'terms' => '1',
            'location_status' => 'limited',
        ])
            ->assertRedirect(route('vacancies.index'))
            ->assertCookie(EnsureWelcomeAccepted::TERMS_COOKIE);
    }

    public function test_cookie_notice_is_shown_after_accepting_with_limited_location(): void
    {
        $this->withSession([
            'terms_accepted_version' => EnsureWelcomeAccepted::TERMS_VERSION,
            'location_mode' => 'limited',
        ])->withCookie(
            EnsureWelcomeAccepted::TERMS_COOKIE,
            EnsureWelcomeAccepted::TERMS_VERSION
        )->get(route('vacancies.index'))
            ->assertOk()
            ->assertSee('Utilizamos cookies');
    }

    public function test_cookie_notice_is_shown_after_accepting_with_precise_location(): void
    {
        $this->withSession([
            'terms_accepted_version' => EnsureWelcomeAccepted::TERMS_VERSION,
            'location_mode' => 'available',
        ])->withCookie(
            EnsureWelcomeAccepted::TERMS_COOKIE,
            EnsureWelcomeAccepted::TERMS_VERSION
        )->get(route('vacancies.index'))
            ->assertOk()
            ->assertSee('Utilizamos cookies');
    }

    public function test_cookie_notice_is_shown_even_when_only_the_session_holds_the_acceptance(): void
    {
        $this->withSession([
            'terms_accepted_version' => EnsureWelcomeAccepted::TERMS_VERSION,
            'location_mode' => 'limited',
        ])->get(route('vacancies.index'))
            ->assertOk()
            ->assertSee('Utilizamos cookies');
    }

    public function test_cookie_notice_is_hidden_once_acknowledged_by_cookie(): void
    {
        $this->withSession([
            'terms_accepted_version' => EnsureWelcomeAccepted::TERMS_VERSION,
            'location_mode' => 'limited',
        ])->withCookie(
            EnsureWelcomeAccepted::COOKIE_NOTICE_COOKIE,
            '1'
        )->get(route('vacancies.index'))
            ->assertOk()
            ->assertDontSee('Utilizamos cookies');
    }

    public function test_cookie_notice_is_hidden_once_acknowledged_by_session(): void
    {
        $this->withSession([
            'terms_accepted_version' => EnsureWelcomeAccepted::TERMS_VERSION,
            'cookie_notice_acknowledged' => true,
            'location_mode' => 'limited',
        ])->get(route('vacancies.index'))
            ->assertOk()
            ->assertDontSee('Utilizamos cookies');
    }

    public function test_cookie_notice_is_not_shown_on_the_welcome_page(): void
    {
        $this->withSession([
            'terms_accepted_version' => EnsureWelcomeAccepted::TERMS_VERSION,
        ])->get(route('welcome'))
            ->assertOk()
            ->assertDontSee('Utilizamos cookies');
    }

    public function test_acknowledging_cookies_stays_on_the_previous_page_and_stores_the_choice(): void
    {
        $response = $this->withSession([
            'terms_accepted_version' => EnsureWelcomeAccepted::TERMS_VERSION,
        ])->from(route('vacancies.index'))
            ->post(route('cookies.acknowledge'));

        $response->assertRedirect(route('vacancies.index'));
        $response->assertSessionHas('cookie_notice_acknowledged', true);
        $response->assertCookie(EnsureWelcomeAccepted::COOKIE_NOTICE_COOKIE);
    }

    public function test_acknowledging_cookies_without_referer_goes_to_vacancies_not_welcome(): void
    {
        $this->withSession([
            'terms_accepted_version' => EnsureWelcomeAccepted::TERMS_VERSION,
        ])->post(route('cookies.acknowledge'))
            ->assertRedirect(route('vacancies.index'));
    }

    public function test_background_submission_returns_the_destination_instead_of_a_redirect_page(): void
    {
        $this->mock(IpLocationResolver::class, function ($mock): void {
            $mock->shouldReceive('resolve')->once()->andReturn(null);
        });

        $response = $this->postJson(route('welcome.accept'), [
            'terms' => '1',
            'location_status' => 'limited',
        ]);

        $response->assertOk();
        $response->assertExactJson(['redirect' => route('vacancies.index')]);
        $response->assertSessionHas(
            'terms_accepted_version',
            EnsureWelcomeAccepted::TERMS_VERSION
        );
        $response->assertSessionHas('location_notice');
        $response->assertCookie(EnsureWelcomeAccepted::TERMS_COOKIE);
    }

    public function test_background_submission_reports_validation_errors_as_json(): void
    {
        $this->postJson(route('welcome.accept'), [
            'location_status' => 'limited',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('terms');
    }

    public function test_cookie_returned_by_accept_releases_the_home_page_for_a_fresh_session(): void
    {
        $this->mock(IpLocationResolver::class, function ($mock): void {
            $mock->shouldReceive('resolve')->once()->andReturn(null);
        });

        $accept = $this->postJson(route('welcome.accept'), [
            'terms' => '1',
            'location_status' => 'limited',
        ]);

        $accept->assertOk();

        // O navegador devolve exatamente o valor que recebeu; a sessão é nova.
        $rawCookie = $accept->getCookie(EnsureWelcomeAccepted::TERMS_COOKIE, false)->getValue();

        $this->flushSession();

        $this->withUnencryptedCookie(EnsureWelcomeAccepted::TERMS_COOKIE, $rawCookie)
            ->get(route('vacancies.index'))
            ->assertOk();
    }

    public function test_responses_tied_to_the_visitor_are_never_cacheable(): void
    {
        $this->mock(IpLocationResolver::class, function ($mock): void {
            $mock->shouldReceive('resolve')->once()->andReturn(null);
        });

        $redirect = $this->get(route('vacancies.index'));
        $redirect->assertRedirect(route('welcome'));
        $this->assertNoStore($redirect);

        $this->assertNoStore($this->get(route('welcome')));

        $this->assertNoStore($this->postJson(route('welcome.accept'), [
            'terms' => '1',
            'location_status' => 'limited',
        ]));
    }

    public function test_accepting_terms_clears_current_and_legacy_location_keys_but_keeps_guard_state(): void
    {
        $this->mock(IpLocationResolver::class, function ($mock): void {
            $mock->shouldReceive('resolve')->once()->andReturn(null);
        });

        $this->withSession([
            'location_mode' => 'available',
            'location_latitude' => -22.9,
            'location_longitude' => -43.2,
            'visitor_state_code' => 'RJ',
            'visitor_municipality_ibge' => '3304557',
            'visitor_location_label' => 'Rio de Janeiro - RJ',
            'ip_location_label' => 'Niterói - RJ',
            'visitor_ip_region_label' => 'Nova Iguaçu - RJ',
            'visitor_latitude' => -22.7,
            'visitor_longitude' => -43.4,
            'visitor_ibge_code' => '3303500',
            'visitor_city_name' => 'Nova Iguaçu',
            'visitor_region_label' => 'Nova Iguaçu - RJ',
            'location_proof' => 'stale-proof',
            'location_guard' => [
                'score' => 8.0,
                'locked_until' => time() + 120,
            ],
        ])->post(route('welcome.accept'), ['terms' => '1'])
            ->assertRedirect(route('vacancies.index'))
            ->assertSessionHas('location_mode', 'limited')
            ->assertSessionMissing('location_latitude')
            ->assertSessionMissing('location_longitude')
            ->assertSessionMissing('visitor_state_code')
            ->assertSessionMissing('visitor_municipality_ibge')
            ->assertSessionMissing('visitor_location_label')
            ->assertSessionMissing('ip_location_label')
            ->assertSessionMissing('visitor_ip_region_label')
            ->assertSessionMissing('visitor_latitude')
            ->assertSessionMissing('visitor_longitude')
            ->assertSessionMissing('visitor_ibge_code')
            ->assertSessionMissing('visitor_city_name')
            ->assertSessionMissing('visitor_region_label')
            ->assertSessionMissing('location_proof')
            ->assertSessionHas('location_guard.locked_until');
    }

    private function assertNoStore($response): void
    {
        $cacheControl = (string) $response->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('Cookie', (string) $response->headers->get('Vary'));
    }
}
