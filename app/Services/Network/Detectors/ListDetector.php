<?php

namespace App\Services\Network\Detectors;

use App\Contracts\NetworkDetector;
use App\Services\Network\IpListStore;
use App\Services\Network\NetworkCategory;
use App\Services\Network\NetworkSignal;
use App\Services\Network\SignalConfidence;

/**
 * Procura o IP nas listas baixadas (Tor, VPN, proxy, datacenter e abuso).
 *
 * A consulta é local: nenhuma requisição externa é feita aqui.
 */
class ListDetector implements NetworkDetector
{
    /**
     * @param  array<string, array<string, mixed>>  $sources  configuração network_security.sources
     */
    public function __construct(
        private readonly IpListStore $store,
        private readonly array $sources,
    ) {}

    public function detect(string $ip): array
    {
        $signals = [];

        foreach ($this->sources as $name => $source) {
            if (! ($source['enabled'] ?? true)) {
                continue;
            }

            $category = NetworkCategory::tryFrom((string) ($source['category'] ?? ''));

            if ($category === null || $category === NetworkCategory::Ordinary) {
                continue;
            }

            $set = $this->store->get((string) $name);

            if ($set !== null && $set->contains($ip)) {
                $signals[] = new NetworkSignal(
                    $category,
                    (string) $name,
                    SignalConfidence::fromConfig($source['confidence'] ?? null)
                );
            }
        }

        return $signals;
    }
}
