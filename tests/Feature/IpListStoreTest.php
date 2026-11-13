<?php

namespace Tests\Feature;

use App\Services\Network\IpListStore;
use App\Services\Network\IpRangeSet;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;

class IpListStoreTest extends TestCase
{
    /** @var list<string> */
    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            File::deleteDirectory($directory);
        }

        parent::tearDown();
    }

    private function store(?string $directory = null, int $localTtl = 900): IpListStore
    {
        $directory ??= sys_get_temp_dir().'/ti-vagas-store-'.bin2hex(random_bytes(6));
        $this->directories[] = $directory;

        return new IpListStore(Cache::store(), $directory, $localTtl);
    }

    public function test_a_published_list_can_be_read_back(): void
    {
        $store = $this->store();
        $store->put('tor', IpRangeSet::fromEntries(['198.51.100.10']), ['url' => 'https://exemplo.test/lista']);

        $set = $store->get('tor');

        $this->assertTrue($set->contains('198.51.100.10'));
        $this->assertFalse($set->contains('198.51.100.11'));
        $this->assertSame('https://exemplo.test/lista', $store->meta('tor')['url']);
        $this->assertSame(1, $store->meta('tor')['ranges']);
    }

    public function test_another_server_reads_what_the_scheduler_published_through_the_shared_cache(): void
    {
        // O agendador (GitHub Actions) e o servidor web são máquinas diferentes:
        // só o cache compartilhado liga as duas.
        $scheduler = $this->store();
        $scheduler->put('abuse', IpRangeSet::fromEntries(['203.0.113.0/24']));

        $webDirectory = sys_get_temp_dir().'/ti-vagas-web-'.bin2hex(random_bytes(6));
        $web = $this->store($webDirectory);

        $this->assertDirectoryDoesNotExist($webDirectory);
        $this->assertTrue($web->get('abuse')->contains('203.0.113.50'));
        $this->assertFileExists($webDirectory.'/abuse.php');
    }

    public function test_the_local_copy_is_a_php_file_that_opcache_can_keep_in_memory(): void
    {
        $directory = sys_get_temp_dir().'/ti-vagas-file-'.bin2hex(random_bytes(6));
        $this->store($directory)->put('vpn', IpRangeSet::fromEntries(['198.51.100.20']));

        $payload = require $directory.'/vpn.php';

        $this->assertSame([198 << 24 | 51 << 16 | 100 << 8 | 20], $payload['set']['v4s']);
    }

    public function test_a_list_that_was_never_published_is_null_and_the_cache_is_not_hit_again(): void
    {
        $cache = \Mockery::mock(Repository::class);
        $cache->shouldReceive('get')->once()->andReturn(null);

        $directory = sys_get_temp_dir().'/ti-vagas-miss-'.bin2hex(random_bytes(6));
        $this->directories[] = $directory;

        // Duas instâncias: a segunda não deve consultar o cache (marcador local).
        $this->assertNull((new IpListStore($cache, $directory))->get('tor'));
        $this->assertNull((new IpListStore($cache, $directory))->get('tor'));
    }

    public function test_an_expired_local_copy_is_refreshed_from_the_shared_cache(): void
    {
        $directory = sys_get_temp_dir().'/ti-vagas-ttl-'.bin2hex(random_bytes(6));

        $this->store($directory, localTtl: 0)->put('tor', IpRangeSet::fromEntries(['198.51.100.10']));

        // Outro processo publica uma versão nova no cache compartilhado.
        $other = $this->store();
        $other->put('tor', IpRangeSet::fromEntries(['198.51.100.99']));

        $web = $this->store($directory, localTtl: 0);

        $this->assertTrue($web->get('tor')->contains('198.51.100.99'));
        $this->assertFalse($web->get('tor')->contains('198.51.100.10'));
    }

    public function test_a_fresh_local_copy_is_used_without_touching_the_shared_cache(): void
    {
        $directory = sys_get_temp_dir().'/ti-vagas-fresh-'.bin2hex(random_bytes(6));
        $this->store($directory)->put('tor', IpRangeSet::fromEntries(['198.51.100.10']));

        $cache = \Mockery::mock(Repository::class);
        $cache->shouldNotReceive('get');

        $this->assertTrue((new IpListStore($cache, $directory, 900))->get('tor')->contains('198.51.100.10'));
    }

    public function test_a_stale_local_copy_is_better_than_nothing_when_the_cache_lost_the_list(): void
    {
        $directory = sys_get_temp_dir().'/ti-vagas-stale-'.bin2hex(random_bytes(6));
        $this->store($directory, localTtl: 0)->put('tor', IpRangeSet::fromEntries(['198.51.100.10']));

        Cache::flush();

        $this->assertTrue($this->store($directory, localTtl: 0)->get('tor')->contains('198.51.100.10'));
    }

    public function test_a_corrupted_local_file_is_discarded_and_rebuilt_from_the_cache(): void
    {
        $directory = sys_get_temp_dir().'/ti-vagas-corrupt-'.bin2hex(random_bytes(6));
        $this->store($directory)->put('tor', IpRangeSet::fromEntries(['198.51.100.10']));

        file_put_contents($directory.'/tor.php', '<?php return [ isto nao compila');

        $this->assertTrue($this->store($directory)->get('tor')->contains('198.51.100.10'));
    }

    public function test_a_cache_outage_does_not_break_reading(): void
    {
        $cache = \Mockery::mock(Repository::class);
        $cache->shouldReceive('get')->andThrow(new \RuntimeException('banco fora do ar'));

        $directory = sys_get_temp_dir().'/ti-vagas-down-'.bin2hex(random_bytes(6));
        $this->directories[] = $directory;

        $this->assertNull((new IpListStore($cache, $directory))->get('tor'));
    }

    public function test_list_names_cannot_escape_the_directory(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->store()->get('../../etc/passwd');
    }
}
