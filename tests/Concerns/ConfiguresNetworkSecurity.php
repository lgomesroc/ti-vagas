<?php

namespace Tests\Concerns;

use App\Services\Network\IpListStore;
use App\Services\Network\IpRangeSet;
use Illuminate\Support\Facades\File;

/**
 * Prepara a detecção de redes suspeitas com listas controladas pelo teste.
 *
 * IPs usados nos testes (faixas de documentação, tratadas como públicas):
 * - 198.51.100.10 Tor (confiança alta)
 * - 198.51.100.20 VPN (média)
 * - 198.51.100.30 proxy anônimo (média)
 * - 198.51.100.40 datacenter (média)
 * - 198.51.100.50 abuso, lista conservadora (alta)
 * - 198.51.100.60 abuso, lista colaborativa (média)
 * - 203.0.113.77  rede comum (em nenhuma lista)
 */
trait ConfiguresNetworkSecurity
{
    protected string $networkListsPath = '';

    protected function configureNetworkSecurity(array $overrides = []): void
    {
        $this->networkListsPath = sys_get_temp_dir().'/ti-vagas-network-'.bin2hex(random_bytes(6));

        config([
            'network_security' => array_replace_recursive([
                'mode' => 'enforce',
                'allowlist' => [],
                'actions' => [
                    'vpn' => 'evaluate',
                    'proxy' => 'restrict',
                    'tor' => 'restrict',
                    'datacenter' => 'evaluate',
                    'abusive' => 'block',
                ],
                'behavior' => [
                    'evaluate_requests_per_minute' => 60,
                    'restricted_requests_per_minute' => 30,
                    'strikes_to_escalate' => 5,
                    'strike_window_minutes' => 15,
                    'escalation_minutes' => 15,
                ],
                'crawler_user_agents' => ['Googlebot'],
                'sources' => [
                    'tor' => ['category' => 'tor', 'confidence' => 'high'],
                    'vpn' => ['category' => 'vpn', 'confidence' => 'medium'],
                    'proxy' => ['category' => 'proxy', 'confidence' => 'medium'],
                    'dc' => ['category' => 'datacenter', 'confidence' => 'medium'],
                    'abuse' => ['category' => 'abusive', 'confidence' => 'high'],
                    'community_abuse' => ['category' => 'abusive', 'confidence' => 'medium'],
                ],
                'storage_path' => $this->networkListsPath,
                'local_ttl_seconds' => 900,
                'abuseipdb' => ['key' => null],
            ], $overrides),
        ]);
    }

    /**
     * Publica as listas de teste (um IP por categoria).
     */
    protected function publishNetworkLists(): void
    {
        $this->publishNetworkList('tor', ['198.51.100.10']);
        $this->publishNetworkList('vpn', ['198.51.100.20']);
        $this->publishNetworkList('proxy', ['198.51.100.30']);
        $this->publishNetworkList('dc', ['198.51.100.40']);
        $this->publishNetworkList('abuse', ['198.51.100.50']);
        $this->publishNetworkList('community_abuse', ['198.51.100.60']);
    }

    /**
     * @param  list<string>  $entries
     */
    protected function publishNetworkList(string $name, array $entries): void
    {
        app(IpListStore::class)->put($name, IpRangeSet::fromEntries($entries));
    }

    protected function cleanUpNetworkSecurity(): void
    {
        if ($this->networkListsPath !== '') {
            File::deleteDirectory($this->networkListsPath);
        }
    }

    protected function fromIp(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }
}
