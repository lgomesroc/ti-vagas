<?php

namespace App\Console\Commands;

use App\Services\Network\IpListStore;
use App\Services\Network\IpRangeSet;
use App\Services\Network\ListParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class UpdateNetworkLists extends Command
{
    protected $signature = 'network:update-lists
        {--source=* : Atualiza apenas as fontes informadas (nomes de network_security.sources)}';

    protected $description = 'Baixa as listas de IPs de Tor, VPN, proxies, datacenters e abuso e as guarda no cache.';

    public function handle(IpListStore $store): int
    {
        $only = array_filter((array) $this->option('source'));
        $failures = 0;
        $rows = [];

        foreach ((array) config('network_security.sources') as $name => $source) {
            if (! ($source['enabled'] ?? true)) {
                continue;
            }

            if ($only !== [] && ! in_array($name, $only, true)) {
                continue;
            }

            try {
                $set = $this->import($store, (string) $name, $source);
                $rows[] = [$name, 'atualizada', $set->count(), $set->skipped];
            } catch (Throwable $exception) {
                $failures++;

                Log::warning('network.list_update_failed', [
                    'source' => $name,
                    'error' => $exception->getMessage(),
                ]);

                $rows[] = [$name, 'falhou: '.$exception->getMessage(), '-', '-'];
            }
        }

        $this->table(['Fonte', 'Resultado', 'Faixas', 'Ignoradas'], $rows);

        if ($failures > 0) {
            $this->warn("{$failures} fonte(s) falharam; as listas anteriores foram mantidas.");
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function import(IpListStore $store, string $name, array $source): IpRangeSet
    {
        $import = (array) config('network_security.import');

        $response = Http::timeout((int) ($import['timeout_seconds'] ?? 30))
            ->connectTimeout(10)
            ->withUserAgent('ti-vagas-network-lists (+https://github.com/lgomesroc/ti-vagas)')
            ->get((string) $source['url']);

        if (! $response->successful()) {
            throw new RuntimeException('HTTP '.$response->status());
        }

        $body = $response->body();

        if (strlen($body) > (int) ($import['max_bytes'] ?? 25 * 1024 * 1024)) {
            throw new RuntimeException('resposta maior que o limite');
        }

        $entries = ListParser::parse((string) ($source['format'] ?? 'lines'), $body, $source);

        $set = IpRangeSet::fromEntries(
            $entries,
            (int) ($import['min_ipv4_prefix'] ?? 8),
            (int) ($import['min_ipv6_prefix'] ?? 16),
        );

        $minimum = (int) ($source['min_entries'] ?? 1);

        if ($set->count() < $minimum) {
            throw new RuntimeException("apenas {$set->count()} faixas (mínimo {$minimum})");
        }

        $maxCoverage = (float) ($import['max_ipv4_coverage'] ?? 0.10);

        if ($set->ipv4AddressCount() > $maxCoverage * 4294967296) {
            throw new RuntimeException('a lista cobre uma fração suspeita do espaço IPv4');
        }

        $store->put($name, $set, [
            'url' => (string) $source['url'],
            'category' => (string) ($source['category'] ?? ''),
            'entries' => count($entries),
            'skipped' => $set->skipped,
        ]);

        return $set;
    }
}
