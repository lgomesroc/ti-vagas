<?php

namespace App\Services\Network;

/**
 * Tipo de rede de origem de um acesso.
 *
 * "Ordinary" reúne tudo o que o IP não consegue distinguir: internet
 * residencial, dados móveis e Wi-Fi de cafeterias, restaurantes, hotéis e
 * aeroportos. Todos recebem o mesmo tratamento: acesso liberado.
 */
enum NetworkCategory: string
{
    case Ordinary = 'ordinary';
    case Vpn = 'vpn';
    case AnonymousProxy = 'proxy';
    case Tor = 'tor';
    case Datacenter = 'datacenter';
    case Abusive = 'abusive';

    public function label(): string
    {
        return match ($this) {
            self::Ordinary => 'Rede comum',
            self::Vpn => 'VPN',
            self::AnonymousProxy => 'Proxy anônimo',
            self::Tor => 'Rede Tor',
            self::Datacenter => 'Datacenter ou hospedagem',
            self::Abusive => 'Endereço associado a abuso',
        };
    }

    /**
     * Ordem de desempate quando dois sinais pedem a mesma ação:
     * quanto menor, mais específico e mais grave.
     */
    public function priority(): int
    {
        return match ($this) {
            self::Abusive => 0,
            self::Tor => 1,
            self::AnonymousProxy => 2,
            self::Vpn => 3,
            self::Datacenter => 4,
            self::Ordinary => 5,
        };
    }
}
