<?php

namespace App\Services\Network;

use InvalidArgumentException;

/**
 * Converte o conteúdo baixado de cada fonte em uma lista de IPs e CIDRs.
 *
 * Formatos:
 * - lines:  um IP ou CIDR por linha; aceita comentários após "#" ou ";"
 *           (Tor Project, FireHOL, Spamhaus DROP e listas comunitárias);
 * - aws:    ip-ranges.json da AWS, filtrado pelos serviços informados;
 * - google: cloud.json do Google Cloud.
 */
final class ListParser
{
    /**
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    public static function parse(string $format, string $body, array $options = []): array
    {
        return match ($format) {
            'lines' => self::lines($body),
            'aws' => self::aws($body, $options['services'] ?? ['EC2']),
            'google' => self::google($body),
            default => throw new InvalidArgumentException("Formato de lista desconhecido: {$format}"),
        };
    }

    /**
     * @return list<string>
     */
    private static function lines(string $body): array
    {
        $entries = [];

        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = trim((string) preg_replace('/\s*[#;].*$/', '', $line));

            if ($line === '') {
                continue;
            }

            // Só o primeiro campo da linha é o endereço.
            $entries[] = preg_split('/\s+/', $line)[0];
        }

        return $entries;
    }

    /**
     * @param  list<string>  $services
     * @return list<string>
     */
    private static function aws(string $body, array $services): array
    {
        $data = self::decode($body);
        $entries = [];

        foreach ((array) ($data['prefixes'] ?? []) as $item) {
            if (self::serviceMatches($item, $services) && is_string($item['ip_prefix'] ?? null)) {
                $entries[] = $item['ip_prefix'];
            }
        }

        foreach ((array) ($data['ipv6_prefixes'] ?? []) as $item) {
            if (self::serviceMatches($item, $services) && is_string($item['ipv6_prefix'] ?? null)) {
                $entries[] = $item['ipv6_prefix'];
            }
        }

        return $entries;
    }

    /**
     * @return list<string>
     */
    private static function google(string $body): array
    {
        $entries = [];

        foreach ((array) (self::decode($body)['prefixes'] ?? []) as $item) {
            foreach (['ipv4Prefix', 'ipv6Prefix'] as $field) {
                if (is_string($item[$field] ?? null)) {
                    $entries[] = $item[$field];
                }
            }
        }

        return $entries;
    }

    /**
     * @param  list<string>  $services
     */
    private static function serviceMatches(mixed $item, array $services): bool
    {
        return is_array($item)
            && ($services === [] || in_array($item['service'] ?? null, $services, true));
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $body): array
    {
        $data = json_decode($body, true);

        if (! is_array($data)) {
            throw new InvalidArgumentException('A lista não é um JSON válido.');
        }

        return $data;
    }
}
