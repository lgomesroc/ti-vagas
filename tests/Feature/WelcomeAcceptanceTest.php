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
}
