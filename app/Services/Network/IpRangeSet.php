<?php

namespace App\Services\Network;

/**
 * Conjunto imutável de faixas de IP (IPv4 e IPv6), com busca binária.
 *
 * As faixas são mescladas e ordenadas na criação. IPv4 é guardado como
 * inteiros; IPv6 como strings hexadecimais de 32 caracteres, que se comparam
 * corretamente em ordem lexicográfica.
 */
final class IpRangeSet
{
    /**
     * @param  list<int>  $v4Starts
     * @param  list<int>  $v4Ends
     * @param  list<string>  $v6Starts
     * @param  list<string>  $v6Ends
     */
    private function __construct(
        private readonly array $v4Starts,
        private readonly array $v4Ends,
        private readonly array $v6Starts,
        private readonly array $v6Ends,
        public readonly int $skipped = 0,
    ) {}

    public static function empty(): self
    {
        return new self([], [], [], []);
    }

    /**
     * Monta o conjunto a partir de IPs e CIDRs, um por item.
     *
     * Itens inválidos e faixas mais largas que o prefixo mínimo são ignorados
     * e contados em $skipped. Isso impede que uma lista corrompida ou
     * maliciosa (por exemplo, 0.0.0.0/0) bloqueie todo o tráfego.
     *
     * @param  iterable<mixed>  $entries
     */
    public static function fromEntries(
        iterable $entries,
        int $minIpv4Prefix = 8,
        int $minIpv6Prefix = 16,
    ): self {
        $v4 = [];
        $v6 = [];
        $skipped = 0;

        foreach ($entries as $entry) {
            $parsed = is_string($entry)
                ? self::parseEntry(trim($entry), $minIpv4Prefix, $minIpv6Prefix)
                : null;

            if ($parsed === null) {
                $skipped++;

                continue;
            }

            if ($parsed['family'] === 4) {
                $v4[] = [$parsed['start'], $parsed['end']];
            } else {
                $v6[] = [$parsed['start'], $parsed['end']];
            }
        }

        [$v4Starts, $v4Ends] = self::mergeRanges($v4, 1);
        [$v6Starts, $v6Ends] = self::mergeRanges($v6, 0);

        return new self($v4Starts, $v4Ends, $v6Starts, $v6Ends, $skipped);
    }

    /**
     * @param  array{v4s?: list<int>, v4e?: list<int>, v6s?: list<string>, v6e?: list<string>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['v4s'] ?? [],
            $data['v4e'] ?? [],
            $data['v6s'] ?? [],
            $data['v6e'] ?? [],
        );
    }

    /**
     * @return array{v4s: list<int>, v4e: list<int>, v6s: list<string>, v6e: list<string>}
     */
    public function toArray(): array
    {
        return [
            'v4s' => $this->v4Starts,
            'v4e' => $this->v4Ends,
            'v6s' => $this->v6Starts,
            'v6e' => $this->v6Ends,
        ];
    }

    /**
     * Código PHP (literal de array) do conjunto, compacto e seguro para o
     * OPcache. Só contém inteiros e strings hexadecimais.
     */
    public function toPhp(): string
    {
        $ints = static fn (array $values): string => '['.implode(',', array_map('intval', $values)).']';
        $hex = static fn (array $values): string => '['.implode(',', array_map(
            static fn (string $value): string => "'".preg_replace('/[^0-9a-f]/', '', $value)."'",
            $values
        )).']';

        return "['v4s'=>".$ints($this->v4Starts)
            .",'v4e'=>".$ints($this->v4Ends)
            .",'v6s'=>".$hex($this->v6Starts)
            .",'v6e'=>".$hex($this->v6Ends).']';
    }

    public function contains(string $ip): bool
    {
        $normalized = self::normalize($ip);

        if ($normalized === null) {
            return false;
        }

        return $normalized['family'] === 4
            ? self::search($this->v4Starts, $this->v4Ends, $normalized['key'])
            : self::search($this->v6Starts, $this->v6Ends, $normalized['key']);
    }

    public function isEmpty(): bool
    {
        return $this->v4Starts === [] && $this->v6Starts === [];
    }

    /**
     * Número de faixas (já mescladas).
     */
    public function count(): int
    {
        return count($this->v4Starts) + count($this->v6Starts);
    }

    /**
     * Quantidade de endereços IPv4 cobertos.
     */
    public function ipv4AddressCount(): int
    {
        $total = 0;

        foreach ($this->v4Starts as $index => $start) {
            $total += $this->v4Ends[$index] - $start + 1;
        }

        return $total;
    }

    /**
     * @param  list<int>|list<string>  $starts
     * @param  list<int>|list<string>  $ends
     */
    private static function search(array $starts, array $ends, int|string $key): bool
    {
        $low = 0;
        $high = count($starts) - 1;
        $found = -1;

        while ($low <= $high) {
            $middle = ($low + $high) >> 1;

            if (self::compare($starts[$middle], $key) <= 0) {
                $found = $middle;
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return $found >= 0 && self::compare($key, $ends[$found]) <= 0;
    }

    /**
     * Compara dois endereços da mesma família.
     *
     * IPv6 é guardado como string hexadecimal. Para o PHP, algumas delas
     * parecem números em notação científica (por exemplo, "9e0…01" vale
     * 9e1), e <=> as ordenaria como números, fora da ordem dos endereços.
     * Por isso a comparação é sempre por strcmp.
     */
    private static function compare(int|string $a, int|string $b): int
    {
        return is_int($a) && is_int($b) ? $a <=> $b : strcmp((string) $a, (string) $b);
    }

    /**
     * @return array{family: 4, key: int}|array{family: 6, key: string}|null
     */
    private static function normalize(string $ip): ?array
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return null;
        }

        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            // IPv4 mapeado em IPv6 (::ffff:a.b.c.d).
            $packed = substr($packed, 12);
        }

        if (strlen($packed) === 4) {
            return ['family' => 4, 'key' => (int) unpack('N', $packed)[1]];
        }

        return ['family' => 6, 'key' => bin2hex($packed)];
    }

    /**
     * @return array{family: 4, start: int, end: int}|array{family: 6, start: string, end: string}|null
     */
    private static function parseEntry(string $entry, int $minIpv4Prefix, int $minIpv6Prefix): ?array
    {
        if ($entry === '') {
            return null;
        }

        [$address, $prefix] = array_pad(explode('/', $entry, 2), 2, null);

        if ($prefix !== null && ! ctype_digit($prefix)) {
            return null;
        }

        $packed = @inet_pton($address);

        if ($packed === false || ! filter_var($address, FILTER_VALIDATE_IP)) {
            return null;
        }

        $isV4 = strlen($packed) === 4;
        $bits = $isV4 ? 32 : 128;
        $length = $prefix === null ? $bits : (int) $prefix;

        if ($length > $bits || $length < ($isV4 ? $minIpv4Prefix : $minIpv6Prefix)) {
            return null;
        }

        if ($isV4) {
            $mask = $length === 0 ? 0 : (0xFFFFFFFF << (32 - $length)) & 0xFFFFFFFF;
            $start = ((int) unpack('N', $packed)[1]) & $mask;

            return [
                'family' => 4,
                'start' => $start,
                'end' => $start | (~$mask & 0xFFFFFFFF),
            ];
        }

        $start = '';
        $end = '';

        for ($index = 0; $index < 16; $index++) {
            $maskBits = max(0, min(8, $length - $index * 8));
            $mask = $maskBits === 0 ? 0 : (0xFF << (8 - $maskBits)) & 0xFF;
            $byte = ord($packed[$index]) & $mask;

            $start .= chr($byte);
            $end .= chr($byte | (~$mask & 0xFF));
        }

        return ['family' => 6, 'start' => bin2hex($start), 'end' => bin2hex($end)];
    }

    /**
     * Ordena e mescla faixas sobrepostas (e, no IPv4, adjacentes).
     *
     * @param  list<array{0: int|string, 1: int|string}>  $ranges
     * @return array{0: list<int|string>, 1: list<int|string>}
     */
    private static function mergeRanges(array $ranges, int $adjacency): array
    {
        if ($ranges === []) {
            return [[], []];
        }

        usort($ranges, static fn (array $a, array $b): int => self::compare($a[0], $b[0]));

        $starts = [];
        $ends = [];
        $currentStart = $ranges[0][0];
        $currentEnd = $ranges[0][1];

        foreach ($ranges as [$start, $end]) {
            $touches = is_int($currentEnd)
                ? $start <= $currentEnd + $adjacency
                : self::compare($start, $currentEnd) <= 0;

            if ($touches) {
                if (self::compare($end, $currentEnd) > 0) {
                    $currentEnd = $end;
                }

                continue;
            }

            $starts[] = $currentStart;
            $ends[] = $currentEnd;
            $currentStart = $start;
            $currentEnd = $end;
        }

        $starts[] = $currentStart;
        $ends[] = $currentEnd;

        return [$starts, $ends];
    }
}
