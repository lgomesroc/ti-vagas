<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureWelcomeAccepted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForceHttpsTest extends TestCase
{
    use RefreshDatabase;

    private function enable(): void
    {
        config(['app.force_https' => true]);
    }

    public function test_http_requests_are_redirected_to_https_permanently(): void
    {
        $this->enable();

        $this->get('http://ti.test/boas-vindas?q=php')
            ->assertStatus(301)
            ->assertRedirect('https://ti.test/boas-vindas?q=php');
    }

    public function test_http_does_not_create_a_session_or_cookies(): void
    {
        $this->enable();

        $response = $this->get('http://ti.test/');

        $response->assertStatus(301);
        $this->assertCount(0, $response->headers->getCookies());
    }

    public function test_requests_behind_the_proxy_with_forwarded_proto_https_are_served(): void
    {
        $this->enable();

        $this->withCookie(EnsureWelcomeAccepted::TERMS_COOKIE, EnsureWelcomeAccepted::TERMS_VERSION)
            ->get('http://ti.test/', ['X-Forwarded-Proto' => 'https'])
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000')
            ->assertHeader('Content-Security-Policy', 'upgrade-insecure-requests');
    }

    public function test_the_health_endpoint_is_reachable_over_plain_http(): void
    {
        $this->enable();

        $this->get('http://ti.test/up')->assertOk();
    }

    public function test_non_get_requests_over_http_are_refused_instead_of_redirected(): void
    {
        $this->enable();

        $this->post('http://ti.test/boas-vindas/aceitar', ['terms' => '1'])->assertStatus(400);
    }

    public function test_generated_urls_and_canonical_use_https(): void
    {
        $this->enable();
        config(['app.url' => 'http://ti.test']);
        (new \App\Providers\AppServiceProvider($this->app))->boot();

        $this->get('https://ti.test/boas-vindas')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="https://ti.test/boas-vindas">', false)
            ->assertSee('<meta property="og:url" content="https://ti.test/boas-vindas">', false);

        $this->assertStringStartsWith('https://', route('welcome'));
    }

    public function test_nothing_changes_when_the_option_is_off(): void
    {
        config(['app.force_https' => false]);

        $this->get('http://ti.test/boas-vindas')
            ->assertOk()
            ->assertHeaderMissing('Strict-Transport-Security');
    }
}
