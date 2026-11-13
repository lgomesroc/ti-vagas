<?php

namespace Tests\Feature;

use App\Http\Middleware\EvaluateNetworkReputation;
use App\Services\Network\Detectors\AbuseIpDbDetector;
use App\Services\Network\NetworkAction;
use App\Services\Network\NetworkCategory;
use App\Services\Network\NetworkClassifier;
use App\Services\Network\SignalConfidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresNetworkSecurity;
use Tests\TestCase;

class AbuseIpDbDetectorTest extends TestCase
{
    use ConfiguresNetworkSecurity;
    use RefreshDatabase;

    private const IP = '203.0.113.9';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureNetworkSecurity();
    }

    protected function tearDown(): void
    {
        $this->cleanUpNetworkSecurity();

        parent::tearDown();
    }

    private function detector(?string $key = 'chave-de-teste', int $dailyLimit = 900): AbuseIpDbDetector
    {
        return new AbuseIpDbDetector($key, $dailyLimit, minScore: 75, cacheHours: 24);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fakeApi(array $data): void
    {
        Http::fake(['api.abuseipdb.com/*' => Http::response(['data' => $data])]);
    }

    public function test_without_an_api_key_it_is_disabled_and_never_calls_the_api(): void
    {
        Http::fake();

        $detector = $this->detector(null);
        $detector->warm(self::IP);

        $this->assertFalse($detector->enabled());
        $this->assertSame([], $detector->detect(self::IP));
        Http::assertNothingSent();
    }

    public function test_detect_only_reads_the_cache_and_never_makes_a_request(): void
    {
        Http::fake();

        $this->assertSame([], $this->detector()->detect(self::IP));

        Http::assertNothingSent();
    }

    public function test_warm_queries_the_api_with_the_key_and_caches_the_answer(): void
    {
        $this->fakeApi(['abuseConfidenceScore' => 0, 'usageType' => 'Fixed Line ISP', 'isTor' => false]);

        $detector = $this->detector();
        $detector->warm(self::IP);
        $detector->warm(self::IP);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Key', 'chave-de-teste')
            && $request['ipAddress'] === self::IP
            && str_starts_with($request->url(), 'https://api.abuseipdb.com/api/v2/check'));
    }

    public function test_a_clean_residential_or_mobile_address_produces_no_signals(): void
    {
        foreach (['Fixed Line ISP', 'Mobile ISP', 'Commercial', 'University/College/School', 'Content Delivery Network'] as $usage) {
            $this->fakeApi(['abuseConfidenceScore' => 3, 'usageType' => $usage, 'isTor' => false]);

            $detector = $this->detector();
            $detector->warm('203.0.113.'.random_int(10, 200));
        }

        $this->fakeApi(['abuseConfidenceScore' => 3, 'usageType' => 'Mobile ISP', 'isTor' => false]);
        $detector = $this->detector();
        $detector->warm(self::IP);

        $this->assertSame([], $detector->detect(self::IP));
    }

    public function test_datacenter_tor_and_abuse_become_medium_confidence_signals(): void
    {
        $this->fakeApi([
            'abuseConfidenceScore' => 90,
            'usageType' => 'Data Center/Web Hosting/Transit',
            'isTor' => true,
        ]);

        $detector = $this->detector();
        $detector->warm(self::IP);
        $signals = $detector->detect(self::IP);

        $categories = array_map(fn ($signal) => $signal->category, $signals);

        $this->assertEqualsCanonicalizing(
            [NetworkCategory::Tor, NetworkCategory::Datacenter, NetworkCategory::Abusive],
            $categories
        );

        // Dados colaborativos nunca levam a bloqueio automático.
        foreach ($signals as $signal) {
            $this->assertSame('abuseipdb', $signal->source);
            $this->assertSame(SignalConfidence::Medium, $signal->confidence);
        }
    }

    public function test_a_score_below_the_threshold_is_not_abuse(): void
    {
        $this->fakeApi(['abuseConfidenceScore' => 74, 'usageType' => 'Fixed Line ISP', 'isTor' => false]);

        $detector = $this->detector();
        $detector->warm(self::IP);

        $this->assertSame([], $detector->detect(self::IP));
    }

    public function test_addresses_on_the_providers_own_allowlist_get_no_signals(): void
    {
        $this->fakeApi([
            'abuseConfidenceScore' => 100,
            'usageType' => 'Data Center/Web Hosting/Transit',
            'isTor' => false,
            'isWhitelisted' => true,
        ]);

        $detector = $this->detector();
        $detector->warm(self::IP);

        $this->assertSame([], $detector->detect(self::IP));
    }

    public function test_the_daily_limit_protects_the_free_tier(): void
    {
        $this->fakeApi(['abuseConfidenceScore' => 0, 'usageType' => 'Fixed Line ISP', 'isTor' => false]);

        $detector = $this->detector(dailyLimit: 2);

        foreach (['203.0.113.1', '203.0.113.2', '203.0.113.3', '203.0.113.4'] as $ip) {
            $detector->warm($ip);
        }

        Http::assertSentCount(2);
    }

    public function test_a_429_from_the_provider_pauses_further_queries(): void
    {
        Http::fake(['api.abuseipdb.com/*' => Http::response('', 429, ['Retry-After' => '3600'])]);

        $detector = $this->detector();
        $detector->warm('203.0.113.1');
        $detector->warm('203.0.113.2');

        Http::assertSentCount(1);
    }

    public function test_provider_errors_and_timeouts_fail_open(): void
    {
        Http::fake(['api.abuseipdb.com/*' => Http::response('erro', 500)]);

        $detector = $this->detector();
        $detector->warm(self::IP);

        $this->assertSame([], $detector->detect(self::IP));

        Http::fake(['api.abuseipdb.com/*' => fn () => throw new ConnectionException('timeout')]);
        $detector->warm('203.0.113.55');

        $this->assertSame([], $detector->detect('203.0.113.55'));
    }

    public function test_the_lookup_happens_after_the_response_and_applies_from_the_next_request(): void
    {
        config([
            'network_security.abuseipdb.key' => 'chave-de-teste',
            'network_security.abuseipdb.min_score' => 75,
        ]);
        $this->app->forgetInstance(AbuseIpDbDetector::class);
        $this->app->forgetInstance(NetworkClassifier::class);
        $this->app->forgetInstance(EvaluateNetworkReputation::class);

        $this->fakeApi([
            'abuseConfidenceScore' => 95,
            'usageType' => 'Data Center/Web Hosting/Transit',
            'isTor' => false,
        ]);

        // Primeira requisição: IP ainda desconhecido, passa sem esperar a API.
        $first = $this->fromIp(self::IP)->get(route('welcome'));
        $first->assertOk();
        $this->assertTrue($first->baseRequest->attributes->get(EvaluateNetworkReputation::ATTRIBUTE)->isOrdinary());
        Http::assertSentCount(1);

        // Seguintes: o resultado em cache já classifica. Abuso colaborativo
        // (confiança média) restringe, nunca bloqueia.
        $second = $this->fromIp(self::IP)->get(route('welcome'));
        $second->assertOk();

        $assessment = $second->baseRequest->attributes->get(EvaluateNetworkReputation::ATTRIBUTE);
        $this->assertSame(NetworkCategory::Abusive, $assessment->category);
        $this->assertSame(NetworkAction::Restrict, $assessment->action);
        Http::assertSentCount(1);
    }

    public function test_the_middleware_makes_no_external_lookup_without_a_key(): void
    {
        Http::fake();

        $this->fromIp(self::IP)->get(route('welcome'))->assertOk();

        Http::assertNothingSent();
    }
}
