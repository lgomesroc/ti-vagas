<?php

namespace Tests\Feature;

use App\Services\Network\IpListStore;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresNetworkSecurity;
use Tests\TestCase;

class NetworkListsCommandTest extends TestCase
{
    use ConfiguresNetworkSecurity;

    private const TOR_URL = 'https://tor.test/lista';

    private const VPN_URL = 'https://vpn.test/lista';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureNetworkSecurity([
            'sources' => [
                'tor' => ['enabled' => true, 'url' => self::TOR_URL, 'format' => 'lines', 'min_entries' => 2],
                'vpn' => ['enabled' => true, 'url' => self::VPN_URL, 'format' => 'lines', 'min_entries' => 2],
                'community_abuse' => ['enabled' => false, 'url' => 'https://nunca.test/'],
                'dc' => ['enabled' => false],
                'proxy' => ['enabled' => false],
                'abuse' => ['enabled' => false],
            ],
            'import' => [
                'min_ipv4_prefix' => 8,
                'min_ipv6_prefix' => 16,
                'max_ipv4_coverage' => 0.10,
                'max_bytes' => 1024 * 1024,
                'timeout_seconds' => 5,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanUpNetworkSecurity();

        parent::tearDown();
    }

    public function test_it_downloads_and_publishes_every_enabled_source(): void
    {
        Http::fake([
            self::TOR_URL => Http::response("# exit nodes\n198.51.100.10\n198.51.100.15\n"),
            self::VPN_URL => Http::response("198.51.100.20/30\n203.0.113.0/24\n"),
        ]);

        $this->artisan('network:update-lists')->assertSuccessful();

        Http::assertSentCount(2);

        $store = app(IpListStore::class);
        $this->assertTrue($store->get('tor')->contains('198.51.100.15'));
        $this->assertTrue($store->get('vpn')->contains('198.51.100.22'));
        $this->assertFalse($store->get('vpn')->contains('198.51.100.30'));
        $this->assertNull($store->get('dc'));
    }

    public function test_it_can_update_only_the_named_sources(): void
    {
        Http::fake([self::TOR_URL => Http::response("198.51.100.10\n198.51.100.15\n")]);

        $this->artisan('network:update-lists', ['--source' => ['tor']])->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertNull(app(IpListStore::class)->get('vpn'));
    }

    public function test_a_failing_source_keeps_the_previous_list_and_fails_the_command(): void
    {
        $this->publishNetworkList('tor', ['198.51.100.10']);
        $this->publishNetworkList('vpn', ['198.51.100.20']);

        Http::fake([
            self::TOR_URL => Http::response('erro', 500),
            self::VPN_URL => Http::response("198.51.100.77\n198.51.100.80\n"),
        ]);

        $this->artisan('network:update-lists')->assertFailed();

        $store = app(IpListStore::class);
        $this->assertTrue($store->get('tor')->contains('198.51.100.10'));
        $this->assertTrue($store->get('vpn')->contains('198.51.100.77'));
    }

    public function test_a_suspiciously_small_list_does_not_replace_a_good_one(): void
    {
        // Página de erro ou resposta vazia não podem apagar a proteção.
        $this->publishNetworkList('tor', ['198.51.100.10', '198.51.100.15', '198.51.100.12']);

        Http::fake([
            self::TOR_URL => Http::response("<html>Service unavailable</html>\n"),
            self::VPN_URL => Http::response("198.51.100.20\n198.51.100.25\n"),
        ]);

        $this->artisan('network:update-lists')->assertFailed();

        $this->assertTrue(app(IpListStore::class)->get('tor')->contains('198.51.100.12'));
    }

    public function test_a_list_covering_too_much_of_the_internet_is_rejected(): void
    {
        // 0.0.0.0/0 e prefixos muito largos são descartados na leitura, mas uma
        // lista inteira de blocos enormes também não pode virar bloqueio geral.
        config(['network_security.import.max_ipv4_coverage' => 0.001]);

        Http::fake([
            self::TOR_URL => Http::response("3.0.0.0/8\n5.0.0.0/8\n"),
            self::VPN_URL => Http::response("198.51.100.20\n198.51.100.25\n"),
        ]);

        $this->artisan('network:update-lists')
            ->expectsOutputToContain('fração suspeita')
            ->assertFailed();

        $this->assertNull(app(IpListStore::class)->get('tor'));
        $this->assertNotNull(app(IpListStore::class)->get('vpn'));
    }

    public function test_a_response_larger_than_the_limit_is_rejected(): void
    {
        config(['network_security.import.max_bytes' => 20]);

        Http::fake([
            self::TOR_URL => Http::response(str_repeat("198.51.100.10\n", 10)),
            self::VPN_URL => Http::response("198.51.100.20\n198.51.100.25\n"),
        ]);

        $this->artisan('network:update-lists')->expectsOutputToContain('maior que o limite')->assertFailed();
    }

    public function test_ranges_that_are_too_wide_are_dropped_but_do_not_fail_the_import(): void
    {
        Http::fake([
            self::TOR_URL => Http::response("0.0.0.0/0\n198.51.100.10\n198.51.100.15\n"),
            self::VPN_URL => Http::response("198.51.100.20\n198.51.100.25\n"),
        ]);

        $this->artisan('network:update-lists')->assertSuccessful();

        $tor = app(IpListStore::class)->get('tor');
        $this->assertFalse($tor->contains('8.8.8.8'));
        $this->assertTrue($tor->contains('198.51.100.10'));
    }

    public function test_the_check_command_reports_list_status_and_classifies_an_ip(): void
    {
        $this->publishNetworkList('tor', ['198.51.100.10']);

        $this->artisan('network:check', ['ip' => '198.51.100.10'])
            ->expectsOutputToContain('Rede Tor')
            ->expectsOutputToContain('tor (tor, confiança high)')
            ->assertSuccessful();

        $this->artisan('network:check', ['ip' => '203.0.113.77'])
            ->expectsOutputToContain('Rede comum')
            ->assertSuccessful();
    }

    public function test_the_check_command_rejects_an_invalid_ip(): void
    {
        $this->artisan('network:check', ['ip' => 'banana'])->expectsOutputToContain('IP inválido')->assertFailed();
    }

    public function test_the_lists_are_refreshed_by_the_scheduler(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('network:update-lists')->assertSuccessful();
    }
}
