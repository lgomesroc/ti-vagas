<?php

namespace Tests\Feature;

use App\Contracts\NetworkDetector;
use App\Http\Middleware\EvaluateNetworkReputation;
use App\Services\Geo\IpLocationResolver;
use App\Services\Network\AbuseTracker;
use App\Services\Network\IpListStore;
use App\Services\Network\IpRangeSet;
use App\Services\Network\NetworkAction;
use App\Services\Network\NetworkAssessment;
use App\Services\Network\NetworkCategory;
use App\Services\Network\NetworkClassifier;
use App\Services\Network\NetworkPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Concerns\ConfiguresNetworkSecurity;
use Tests\TestCase;

class NetworkReputationMiddlewareTest extends TestCase
{
    use ConfiguresNetworkSecurity;
    use RefreshDatabase;

    private const ORDINARY = '203.0.113.77';

    private const TOR = '198.51.100.10';

    private const VPN = '198.51.100.20';

    private const PROXY = '198.51.100.30';

    private const DATACENTER = '198.51.100.40';

    private const ABUSIVE = '198.51.100.50';

    private const COMMUNITY_ABUSIVE = '198.51.100.60';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureNetworkSecurity();
        $this->publishNetworkLists();
    }

    protected function tearDown(): void
    {
        $this->cleanUpNetworkSecurity();

        parent::tearDown();
    }

    // --- Redes comuns: sempre liberadas ---------------------------------

    public function test_ordinary_networks_are_allowed_and_session_works_normally(): void
    {
        $response = $this->fromIp(self::ORDINARY)->get(route('welcome'));

        $response->assertOk();
        $response->assertCookie(config('session.cookie'));
        $this->assertTrue($response->baseRequest->attributes->get(EvaluateNetworkReputation::ATTRIBUTE)->isOrdinary());
    }

    public function test_a_shared_public_ip_is_never_limited_or_punished_however_busy(): void
    {
        // Wi-Fi de cafeteria, hotel ou aeroporto: muita gente no mesmo IP.
        config(['network_security.behavior.evaluate_requests_per_minute' => 2]);
        config(['network_security.behavior.restricted_requests_per_minute' => 1]);

        $tracker = app(AbuseTracker::class);

        for ($i = 0; $i < 25; $i++) {
            $tracker->recordStrike(self::ORDINARY);
            $this->fromIp(self::ORDINARY)->get(route('welcome'))->assertOk();
        }
    }

    public function test_a_public_ip_is_not_a_suspicious_ip_when_no_list_has_it(): void
    {
        foreach (['203.0.113.1', '192.0.2.50', '8.8.8.8', '177.12.34.56'] as $ip) {
            $this->fromIp($ip)->get(route('welcome'))->assertOk();
        }
    }

    public function test_private_and_loopback_addresses_are_never_classified_even_if_listed(): void
    {
        $this->publishNetworkList('abuse', ['10.0.0.0/8', '127.0.0.0/8', '203.0.113.0/24']);

        $this->fromIp('10.1.2.3')->get(route('welcome'))->assertOk();
        $this->fromIp('127.0.0.1')->get(route('welcome'))->assertOk();
        $this->fromIp('203.0.113.9')->get(route('welcome'))->assertForbidden();
    }

    public function test_missing_lists_fail_open(): void
    {
        // Sem nenhuma lista no cache compartilhado nem em disco.
        Cache::flush();
        $this->cleanUpNetworkSecurity();
        $this->configureNetworkSecurity();
        $this->app->forgetInstance(IpListStore::class);
        $this->app->forgetInstance(NetworkClassifier::class);

        $this->fromIp(self::ABUSIVE)->get(route('welcome'))->assertOk();
        $this->fromIp(self::TOR)->get(route('welcome'))->assertOk();
    }

    public function test_a_failing_classifier_never_takes_the_site_down(): void
    {
        $this->mock(NetworkClassifier::class, function ($mock): void {
            $mock->shouldReceive('assess')->andThrow(new RuntimeException('cache fora do ar'));
        });

        $this->fromIp(self::ABUSIVE)->get(route('welcome'))->assertOk();
    }

    public function test_a_failing_detector_is_ignored_and_the_rest_still_work(): void
    {
        $broken = new class implements NetworkDetector
        {
            public function detect(string $ip): array
            {
                throw new RuntimeException('detector quebrado');
            }
        };

        $classifier = new NetworkClassifier(
            [$broken],
            new NetworkPolicy,
            IpRangeSet::empty(),
        );

        $this->assertTrue($classifier->assess(self::TOR)->isOrdinary());
    }

    // --- Bloqueio -------------------------------------------------------

    public function test_abusive_addresses_from_a_conservative_list_are_blocked_before_any_session_is_created(): void
    {
        $response = $this->fromIp(self::ABUSIVE)->get(route('welcome'));

        $response->assertForbidden();
        $response->assertSee('Acesso indisponível a partir desta rede');
        $response->assertSee('Esta verificação não usa a sua localização');
        $response->assertHeader('Cache-Control', 'no-store, private');
        $response->assertCookieMissing(config('session.cookie'));
    }

    public function test_blocked_requests_get_json_when_the_client_expects_it(): void
    {
        $this->fromIp(self::ABUSIVE)
            ->getJson(route('welcome'))
            ->assertForbidden()
            ->assertJsonPath('code', 'network_blocked');
    }

    public function test_a_collaborative_list_can_restrict_but_never_block(): void
    {
        // Dados colaborativos podem acusar IPs compartilhados (CGNAT, Wi-Fi
        // público): confiança média limita a ação a "restrict".
        $this->fromIp(self::COMMUNITY_ABUSIVE)->get(route('welcome'))->assertOk();
    }

    public function test_the_operator_can_choose_to_block_tor_and_proxies(): void
    {
        config(['network_security.actions.tor' => 'block', 'network_security.actions.proxy' => 'block']);

        $this->fromIp(self::TOR)->get(route('welcome'))->assertForbidden();
        // Proxy tem confiança média: mesmo configurado como bloqueio, só restringe.
        $this->fromIp(self::PROXY)->get(route('welcome'))->assertOk();
    }

    // --- Restrição ------------------------------------------------------

    public function test_tor_and_anonymous_proxies_are_restricted_to_a_lower_request_rate(): void
    {
        config(['network_security.behavior.restricted_requests_per_minute' => 3]);

        foreach ([self::TOR, self::PROXY] as $ip) {
            for ($i = 0; $i < 3; $i++) {
                $this->fromIp($ip)->get(route('welcome'))->assertOk();
            }

            $response = $this->fromIp($ip)->get(route('welcome'));

            $response->assertStatus(429);
            $response->assertHeader('Retry-After');
            $response->assertSee('Muitas requisições a partir desta rede');
        }
    }

    public function test_restriction_returns_json_for_api_clients(): void
    {
        config(['network_security.behavior.restricted_requests_per_minute' => 1]);

        $this->fromIp(self::TOR)->getJson(route('welcome'))->assertOk();
        $this->fromIp(self::TOR)->getJson(route('welcome'))
            ->assertStatus(429)
            ->assertJsonPath('code', 'network_restricted');
    }

    public function test_restricting_one_address_does_not_affect_another(): void
    {
        config(['network_security.behavior.restricted_requests_per_minute' => 1]);

        $this->fromIp(self::TOR)->get(route('welcome'))->assertOk();
        $this->fromIp(self::TOR)->get(route('welcome'))->assertStatus(429);

        $this->fromIp(self::ORDINARY)->get(route('welcome'))->assertOk();
        $this->fromIp(self::PROXY)->get(route('welcome'))->assertOk();
    }

    // --- Avaliação de comportamento (VPN e datacenter) -------------------

    public function test_vpn_and_datacenter_are_allowed_and_only_observed_at_normal_volume(): void
    {
        foreach ([self::VPN, self::DATACENTER] as $ip) {
            for ($i = 0; $i < 10; $i++) {
                $this->fromIp($ip)->get(route('welcome'))->assertOk();
            }
        }
    }

    public function test_a_vpn_that_behaves_like_a_bot_is_restricted_temporarily(): void
    {
        config([
            'network_security.behavior.evaluate_requests_per_minute' => 5,
            'network_security.behavior.restricted_requests_per_minute' => 2,
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->fromIp(self::VPN)->get(route('welcome'))->assertOk();
        }

        // A sexta requisição passa do limite: escala para "restrict".
        $this->fromIp(self::VPN)->get(route('welcome'))->assertOk();
        $this->fromIp(self::VPN)->get(route('welcome'))->assertOk();
        $this->fromIp(self::VPN)->get(route('welcome'))->assertStatus(429);

        $this->assertTrue(app(AbuseTracker::class)->isEscalated(self::VPN));
    }

    public function test_repeated_rate_limit_hits_escalate_a_datacenter_address(): void
    {
        config(['network_security.behavior.restricted_requests_per_minute' => 1]);

        $tracker = app(AbuseTracker::class);

        for ($i = 0; $i < 5; $i++) {
            $tracker->recordStrike(self::DATACENTER);
        }

        $this->fromIp(self::DATACENTER)->get(route('welcome'))->assertOk();
        $this->fromIp(self::DATACENTER)->get(route('welcome'))->assertStatus(429);
    }

    public function test_search_and_ad_crawlers_are_not_escalated_from_datacenters(): void
    {
        config([
            'network_security.behavior.evaluate_requests_per_minute' => 3,
            'network_security.behavior.restricted_requests_per_minute' => 1,
        ]);

        for ($i = 0; $i < 12; $i++) {
            $this->fromIp(self::DATACENTER)
                ->withHeader('User-Agent', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)')
                ->get(route('welcome'))
                ->assertOk();
        }

        $this->assertFalse(app(AbuseTracker::class)->isEscalated(self::DATACENTER));
    }

    public function test_the_same_volume_without_a_crawler_user_agent_is_escalated(): void
    {
        config(['network_security.behavior.evaluate_requests_per_minute' => 3]);

        for ($i = 0; $i < 5; $i++) {
            $this->fromIp(self::DATACENTER)->get(route('welcome'));
        }

        $this->assertTrue(app(AbuseTracker::class)->isEscalated(self::DATACENTER));
    }

    public function test_throttled_responses_count_as_strikes_only_for_evaluated_addresses(): void
    {
        $this->mock(IpLocationResolver::class);

        // POST /cookies/aceitar tem o limite padrão de 20 por minuto.
        foreach ([self::VPN, self::ORDINARY] as $ip) {
            for ($i = 0; $i < 20; $i++) {
                $this->assertNotSame(429, $this->fromIp($ip)->post(route('cookies.acknowledge'))->getStatusCode());
            }

            $this->fromIp($ip)->post(route('cookies.acknowledge'))->assertStatus(429);
        }

        $tracker = app(AbuseTracker::class);

        $this->assertSame(1, $tracker->strikes(self::VPN));
        // Rede comum nunca é rastreada: o excesso de uma pessoa não pune as outras.
        $this->assertSame(0, $tracker->strikes(self::ORDINARY));
    }

    // --- Modos, allowlist e saúde ---------------------------------------

    public function test_monitor_mode_records_but_never_blocks_or_limits(): void
    {
        config([
            'network_security.mode' => 'monitor',
            'network_security.behavior.restricted_requests_per_minute' => 1,
        ]);

        for ($i = 0; $i < 4; $i++) {
            $this->fromIp(self::ABUSIVE)->get(route('welcome'))->assertOk();
            $this->fromIp(self::TOR)->get(route('welcome'))->assertOk();
        }
    }

    public function test_off_mode_does_not_classify_at_all(): void
    {
        config(['network_security.mode' => 'off']);

        $response = $this->fromIp(self::ABUSIVE)->get(route('welcome'));

        $response->assertOk();
        $this->assertNull($response->baseRequest->attributes->get(EvaluateNetworkReputation::ATTRIBUTE));
    }

    public function test_an_unknown_mode_falls_back_to_enforce(): void
    {
        config(['network_security.mode' => 'talvez']);

        $this->fromIp(self::ABUSIVE)->get(route('welcome'))->assertForbidden();
    }

    public function test_the_allowlist_overrides_every_list(): void
    {
        config(['network_security.allowlist' => ['198.51.100.0/24']]);
        $this->app->forgetInstance(NetworkClassifier::class);

        $this->fromIp(self::ABUSIVE)->get(route('welcome'))->assertOk();
        $this->fromIp(self::TOR)->get(route('welcome'))->assertOk();
    }

    public function test_the_health_check_endpoint_is_never_blocked(): void
    {
        // A verificação de saúde da hospedagem sai de datacenters.
        $this->fromIp(self::ABUSIVE)->get('/up')->assertOk();
    }

    public function test_logs_never_contain_the_raw_ip_address(): void
    {
        Log::spy();

        $this->fromIp(self::TOR)->get(route('welcome'));

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                $this->assertSame('network.access', $message);
                $this->assertSame('tor', $context['category']);
                $this->assertSame('restrict', $context['action']);
                $this->assertTrue($context['enforced']);
                $this->assertSame(['tor'], $context['sources']);
                $this->assertStringNotContainsString(self::TOR, json_encode($context));
                $this->assertSame(16, strlen($context['ip_hash']));

                return true;
            })
            ->once();
    }

    public function test_it_logs_once_per_address_and_action_instead_of_on_every_request(): void
    {
        Log::spy();

        for ($i = 0; $i < 5; $i++) {
            $this->fromIp(self::VPN)->get(route('welcome'));
        }

        Log::shouldHaveReceived('info')->with('network.access', \Mockery::any())->once();
    }

    // --- Geolocalização: o IP nunca a substitui --------------------------

    public function test_the_ip_estimate_is_skipped_for_vpn_proxy_tor_and_datacenter_exit_points(): void
    {
        // Em VPN, proxy, Tor ou datacenter o IP é o do ponto de saída.
        $this->mock(IpLocationResolver::class, function ($mock): void {
            $mock->shouldNotReceive('resolve');
        });

        foreach ([self::VPN, self::PROXY, self::TOR, self::DATACENTER] as $ip) {
            $response = $this->fromIp($ip)->postJson(route('welcome.accept'), ['terms' => '1']);

            $response->assertOk()->assertJsonPath('accepted', true);
            $response->assertSessionMissing('visitor_ip_region_label');
        }
    }

    public function test_the_ip_estimate_is_still_used_for_ordinary_networks(): void
    {
        $this->mock(IpLocationResolver::class, function ($mock): void {
            $mock->shouldReceive('resolve')->once()->andReturn(['label' => 'São Paulo - SP']);
        });

        $this->fromIp(self::ORDINARY)
            ->postJson(route('welcome.accept'), ['terms' => '1'])
            ->assertOk()
            ->assertSessionHas('visitor_ip_region_label', 'São Paulo - SP');
    }

    public function test_network_classification_never_grants_or_changes_the_visitor_location(): void
    {
        $this->mock(IpLocationResolver::class, function ($mock): void {
            $mock->shouldReceive('resolve')->andReturn(['label' => 'São Paulo - SP']);
        });

        foreach ([self::ORDINARY, self::VPN, self::DATACENTER] as $ip) {
            $response = $this->fromIp($ip)->postJson(route('welcome.accept'), ['terms' => '1']);

            // Seja qual for a rede, só o navegador pode liberar vagas locais.
            $response->assertOk();
            $response->assertSessionHas('location_mode', 'limited');
            $response->assertSessionMissing('visitor_latitude');
            $response->assertSessionMissing('visitor_state_code');
            $response->assertSessionMissing('visitor_ibge_code');
        }
    }

    public function test_the_assessment_exposes_category_and_action_for_downstream_code(): void
    {
        $response = $this->fromIp(self::VPN)->get(route('welcome'));

        /** @var NetworkAssessment $assessment */
        $assessment = $response->baseRequest->attributes->get(EvaluateNetworkReputation::ATTRIBUTE);

        $this->assertSame(NetworkCategory::Vpn, $assessment->category);
        $this->assertSame(NetworkAction::Evaluate, $assessment->action);
        $this->assertSame(['vpn'], $assessment->sources());
    }
}
