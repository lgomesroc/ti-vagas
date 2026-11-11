<?php

namespace Tests\Feature;

use App\Services\Geo\IpLocationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    private function acceptLimited(string $ip = '198.51.100.10')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson(route('welcome.accept'), [
                'terms' => '1',
                'location_status' => 'limited',
            ]);
    }

    public function test_welcome_accept_is_limited_per_ip(): void
    {
        $this->mock(IpLocationResolver::class, function ($mock): void {
            $mock->shouldReceive('resolve')->andReturn(null);
        });

        for ($i = 0; $i < 20; $i++) {
            $this->acceptLimited()->assertOk();
        }

        $this->acceptLimited()->assertStatus(429);
    }

    public function test_the_limit_of_one_ip_does_not_block_another(): void
    {
        $this->mock(IpLocationResolver::class, function ($mock): void {
            $mock->shouldReceive('resolve')->andReturn(null);
        });

        for ($i = 0; $i < 21; $i++) {
            $this->acceptLimited('198.51.100.10');
        }

        $this->acceptLimited('198.51.100.10')->assertStatus(429);
        $this->acceptLimited('203.0.113.77')->assertOk();
    }

    public function test_cookie_acknowledgement_is_limited_per_ip(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
                ->post(route('cookies.acknowledge'))
                ->assertRedirect();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->post(route('cookies.acknowledge'))
            ->assertStatus(429);
    }

    public function test_external_ip_lookups_have_a_global_cap_even_with_forged_ips(): void
    {
        Http::fake([
            'ipwho.is/*' => Http::response([
                'success' => true,
                'country_code' => 'BR',
                'region_code' => 'RJ',
                'city' => 'Niterói',
                'latitude' => -22.88,
                'longitude' => -43.10,
            ]),
        ]);

        $resolver = app(IpLocationResolver::class);

        // 80 IPs públicos diferentes, como faria quem forja X-Forwarded-For.
        for ($i = 1; $i <= 80; $i++) {
            $resolver->resolve(Request::create('/', 'GET', [], [], [], [
                'REMOTE_ADDR' => '203.0.113.' . $i,
            ]));
        }

        Http::assertSentCount(60);
    }

    public function test_cached_ip_does_not_consume_the_global_cap(): void
    {
        Http::fake([
            'ipwho.is/*' => Http::response([
                'success' => true,
                'country_code' => 'BR',
                'region_code' => 'RJ',
                'city' => 'Niterói',
                'latitude' => -22.88,
                'longitude' => -43.10,
            ]),
        ]);

        $resolver = app(IpLocationResolver::class);
        $request = Request::create('/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.200',
        ]);

        for ($i = 0; $i < 10; $i++) {
            $this->assertNotNull($resolver->resolve($request));
        }

        Http::assertSentCount(1);
    }
}
