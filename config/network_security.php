<?php

/*
|--------------------------------------------------------------------------
| Detecção de VPN, proxy e redes suspeitas
|--------------------------------------------------------------------------
|
| A classificação usa o IP apenas como um sinal de risco de acesso. Ele NÃO
| substitui a geolocalização do navegador e nunca libera vagas locais.
|
| Documentação completa, limitações e falsos positivos:
| docs/deteccao-de-redes-suspeitas.md
|
*/

$list = static fn (string $value): array => array_values(array_filter(
    array_map('trim', explode(',', $value)),
    static fn (string $item): bool => $item !== ''
));

return [

    /*
    | off     — não classifica nem registra nada.
    | monitor — classifica e registra em log, mas nunca bloqueia ou limita.
    | enforce — aplica a política de acesso abaixo.
    |
    | Recomenda-se publicar primeiro em "monitor", observar os logs
    | (network.access) e só então ativar "enforce".
    */
    'mode' => env('NETWORK_SECURITY_MODE', 'enforce'),

    /*
    | IPs ou CIDRs que nunca são classificados (monitoramento, escritório,
    | administrador). Separe por vírgula.
    */
    'allowlist' => $list((string) env('NETWORK_ALLOWLIST', '')),

    /*
    | Política de acesso por categoria: allow | evaluate | restrict | block.
    |
    | "ordinary" (internet residencial, dados móveis e Wi-Fi público de
    | cafeterias, restaurantes, hotéis e aeroportos) é sempre "allow" e não
    | pode ser alterada: pelo IP não há como distinguir essas redes entre si
    | nem de uma rede comum, e IPs compartilhados jamais são bloqueados
    | automaticamente.
    |
    | evaluate — permite, registra e observa o comportamento; vira "restrict"
    |            temporariamente se o IP se comportar como robô.
    | restrict — permite com limite de requisições mais baixo.
    | block    — responde 403.
    */
    'actions' => [
        'vpn' => env('NETWORK_ACTION_VPN', 'evaluate'),
        'proxy' => env('NETWORK_ACTION_PROXY', 'restrict'),
        'tor' => env('NETWORK_ACTION_TOR', 'restrict'),
        'datacenter' => env('NETWORK_ACTION_DATACENTER', 'evaluate'),
        'abusive' => env('NETWORK_ACTION_ABUSIVE', 'block'),
    ],

    /*
    | Comportamento observado para IPs em "evaluate" e para a aplicação de
    | "restrict".
    */
    'behavior' => [
        // Acima disso, em um minuto, um IP em "evaluate" passa a "restrict".
        'evaluate_requests_per_minute' => (int) env('NETWORK_EVALUATE_RPM', 60),

        // Limite de requisições por minuto de um IP em "restrict".
        'restricted_requests_per_minute' => (int) env('NETWORK_RESTRICTED_RPM', 30),

        // Respostas 429 (limite de requisições) que escalam um IP em "evaluate".
        'strikes_to_escalate' => (int) env('NETWORK_STRIKES_TO_ESCALATE', 5),
        'strike_window_minutes' => 15,

        // Por quanto tempo uma escalada de "evaluate" para "restrict" persiste.
        'escalation_minutes' => 15,
    ],

    /*
    | Robôs de busca e de anúncios legítimos também saem de datacenters.
    | Para eles, a escalada de "evaluate" para "restrict" é ignorada.
    | O User-Agent pode ser forjado: isso só afasta o limite brando de
    | "evaluate" e nunca vale para proxy, Tor ou abuso.
    */
    'crawler_user_agents' => [
        'Googlebot',
        'Google-InspectionTool',
        'Mediapartners-Google',
        'AdsBot-Google',
        'bingbot',
        'DuckDuckBot',
        'Applebot',
        'facebookexternalhit',
        'LinkedInBot',
        'Twitterbot',
    ],

    /*
    | Listas de IPs. São baixadas por "php artisan network:update-lists"
    | (agendado a cada seis horas) e guardadas no cache compartilhado, para
    | que o servidor web use o que o GitHub Actions baixou.
    |
    | confidence: high   — pode levar a "block"
    |             medium — no máximo "restrict"
    |             low    — no máximo "evaluate"
    |
    | min_entries protege contra respostas vazias ou páginas de erro: se a
    | lista baixada tiver menos faixas que isso, a lista anterior é mantida.
    |
    | Confira a licença de cada lista antes de usá-la em produção.
    */
    'sources' => [
        'tor' => [
            'enabled' => (bool) env('NETWORK_SOURCE_TOR', true),
            'category' => 'tor',
            'confidence' => 'high',
            'url' => 'https://check.torproject.org/torbulkexitlist',
            'format' => 'lines',
            'min_entries' => 100,
        ],

        'vpn_ipv4' => [
            'enabled' => (bool) env('NETWORK_SOURCE_VPN', true),
            'category' => 'vpn',
            'confidence' => 'medium',
            'url' => 'https://raw.githubusercontent.com/X4BNet/lists_vpn/main/output/vpn/ipv4.txt',
            'format' => 'lines',
            'min_entries' => 1000,
        ],

        'vpn_ipv6' => [
            'enabled' => (bool) env('NETWORK_SOURCE_VPN', true),
            'category' => 'vpn',
            'confidence' => 'medium',
            'url' => 'https://raw.githubusercontent.com/X4BNet/lists_vpn/main/output/vpn/ipv6.txt',
            'format' => 'lines',
            'min_entries' => 100,
        ],

        'datacenter_ipv4' => [
            'enabled' => (bool) env('NETWORK_SOURCE_DATACENTER', true),
            'category' => 'datacenter',
            'confidence' => 'medium',
            'url' => 'https://raw.githubusercontent.com/X4BNet/lists_vpn/main/output/datacenter/ipv4.txt',
            'format' => 'lines',
            'min_entries' => 5000,
        ],

        'datacenter_ipv6' => [
            'enabled' => (bool) env('NETWORK_SOURCE_DATACENTER', true),
            'category' => 'datacenter',
            'confidence' => 'medium',
            'url' => 'https://raw.githubusercontent.com/X4BNet/lists_vpn/main/output/datacenter/ipv6.txt',
            'format' => 'lines',
            'min_entries' => 500,
        ],

        // Faixas publicadas pelos próprios provedores de nuvem.
        'aws' => [
            'enabled' => (bool) env('NETWORK_SOURCE_AWS', true),
            'category' => 'datacenter',
            'confidence' => 'high',
            'url' => 'https://ip-ranges.amazonaws.com/ip-ranges.json',
            'format' => 'aws',
            'services' => ['EC2'],
            'min_entries' => 500,
        ],

        'gcp' => [
            'enabled' => (bool) env('NETWORK_SOURCE_GCP', true),
            'category' => 'datacenter',
            'confidence' => 'high',
            'url' => 'https://www.gstatic.com/ipranges/cloud.json',
            'format' => 'google',
            'min_entries' => 100,
        ],

        // Proxies abertos vistos nos últimos 7 dias. Os IPs mudam de dono
        // com frequência: por isso a confiança é média (no máximo "restrict").
        'proxy_socks' => [
            'enabled' => (bool) env('NETWORK_SOURCE_PROXY', true),
            'category' => 'proxy',
            'confidence' => 'medium',
            'url' => 'https://raw.githubusercontent.com/firehol/blocklist-ipsets/master/socks_proxy_7d.ipset',
            'format' => 'lines',
            'min_entries' => 100,
        ],

        'proxy_ssl' => [
            'enabled' => (bool) env('NETWORK_SOURCE_PROXY', true),
            'category' => 'proxy',
            'confidence' => 'medium',
            'url' => 'https://raw.githubusercontent.com/firehol/blocklist-ipsets/master/sslproxies_7d.ipset',
            'format' => 'lines',
            'min_entries' => 100,
        ],

        // Spamhaus DROP: redes sequestradas ou controladas por criminosos.
        // Lista conservadora; é a única fonte automática de "block".
        'abuse_spamhaus_drop' => [
            'enabled' => (bool) env('NETWORK_SOURCE_ABUSE', true),
            'category' => 'abusive',
            'confidence' => 'high',
            'url' => 'https://raw.githubusercontent.com/firehol/blocklist-ipsets/master/spamhaus_drop.netset',
            'format' => 'lines',
            'min_entries' => 500,
        ],
    ],

    /*
    | Limites de segurança aplicados ao importar qualquer lista.
    */
    'import' => [
        // Faixas mais largas que estes prefixos são ignoradas (0.0.0.0/0 etc.).
        'min_ipv4_prefix' => 8,
        'min_ipv6_prefix' => 16,

        // Uma lista que cubra mais que esta fração do espaço IPv4 é rejeitada.
        'max_ipv4_coverage' => 0.10,

        'max_bytes' => 25 * 1024 * 1024,
        'timeout_seconds' => 30,
    ],

    /*
    | Onde as listas compiladas ficam em disco neste servidor. A fonte da
    | verdade é o cache compartilhado; o arquivo local só evita buscá-lo a
    | cada requisição e é carregado pelo OPcache.
    */
    'storage_path' => storage_path('app/network-lists'),
    'local_ttl_seconds' => 900,

    /*
    | AbuseIPDB (opcional, plano gratuito de 1.000 consultas por dia).
    | Só é ativado se ABUSEIPDB_API_KEY estiver definida. As consultas são
    | feitas depois que a resposta é enviada ao visitante, e o resultado
    | fica em cache; por isso a primeira requisição de um IP novo não usa
    | esse sinal.
    */
    'abuseipdb' => [
        'key' => env('ABUSEIPDB_API_KEY'),
        'daily_limit' => (int) env('ABUSEIPDB_DAILY_LIMIT', 900),
        'min_score' => (int) env('ABUSEIPDB_MIN_SCORE', 75),
        'cache_hours' => 24,
    ],

];
