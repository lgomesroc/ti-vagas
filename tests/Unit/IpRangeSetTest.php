<?php

namespace Tests\Unit;

use App\Services\Network\IpRangeSet;
use PHPUnit\Framework\TestCase;

class IpRangeSetTest extends TestCase
{
    public function test_it_matches_ipv4_addresses_inside_a_cidr_including_the_edges(): void
    {
        $set = IpRangeSet::fromEntries(['203.0.113.0/24']);

        $this->assertTrue($set->contains('203.0.113.0'));
        $this->assertTrue($set->contains('203.0.113.128'));
        $this->assertTrue($set->contains('203.0.113.255'));
        $this->assertFalse($set->contains('203.0.112.255'));
        $this->assertFalse($set->contains('203.0.114.0'));
    }

    public function test_a_bare_address_is_a_single_ip(): void
    {
        $set = IpRangeSet::fromEntries(['198.51.100.7']);

        $this->assertTrue($set->contains('198.51.100.7'));
        $this->assertFalse($set->contains('198.51.100.6'));
        $this->assertFalse($set->contains('198.51.100.8'));
    }

    public function test_overlapping_and_adjacent_ranges_are_merged(): void
    {
        $set = IpRangeSet::fromEntries([
            '10.0.0.0/25',
            '10.0.0.128/25',   // adjacente
            '10.0.0.64/26',    // contido
            '10.0.2.0/24',
        ]);

        $this->assertSame(2, $set->count());
        $this->assertTrue($set->contains('10.0.0.200'));
        $this->assertFalse($set->contains('10.0.1.1'));
        $this->assertTrue($set->contains('10.0.2.9'));
        $this->assertSame(256 + 256, $set->ipv4AddressCount());
    }

    public function test_it_matches_ipv6(): void
    {
        $set = IpRangeSet::fromEntries(['2001:db8:abcd::/48', '2600:1900::/28']);

        $this->assertTrue($set->contains('2001:db8:abcd::1'));
        $this->assertTrue($set->contains('2001:db8:abcd:ffff:ffff:ffff:ffff:ffff'));
        $this->assertFalse($set->contains('2001:db8:abce::1'));
        $this->assertTrue($set->contains('2600:190f::1'));
        $this->assertFalse($set->contains('2600:1910::1'));
    }

    public function test_ipv6_comparison_is_not_numeric(): void
    {
        // Em hexadecimal, 9e00::1 é "9e000000000000000000000000000001", que o
        // PHP lê como o número 9e1 (notação científica); 1000:: é um inteiro
        // gigante; 5f00:: não é numérico e é comparado como texto. Com <=>
        // isso forma um ciclo (9e00::1 < 1000:: < 5f00:: < 9e00::1), a ordenação
        // fica sem sentido e a busca binária erra.
        $set = IpRangeSet::fromEntries([
            '9e00::1',
            '1000::',
            '5f00::',
            '2001:2000::1',
            '2001:2000::3',
            '5e00::7',
            'a000::/16',
        ]);

        foreach (['9e00::1', '1000::', '5f00::', '2001:2000::1', '2001:2000::3', '5e00::7', 'a000::1', 'a000:ffff::1'] as $inside) {
            $this->assertTrue($set->contains($inside), "{$inside} deveria estar no conjunto");
        }

        foreach (['9e00::2', '1000::1', '5f00::1', '2001:2000::2', '2001:2000::4', '5e00::8', '9fff::1', 'b000::1'] as $outside) {
            $this->assertFalse($set->contains($outside), "{$outside} não deveria estar no conjunto");
        }
    }

    public function test_ipv6_lookup_is_correct_for_addresses_that_look_like_numbers_to_php(): void
    {
        // Caso achado por fuzz contra a comparação binária: com <=> no lugar
        // de strcmp, 5e5b::3 deixava de ser encontrado.
        $addresses = ['9eaa::2', 'ff44::2', '1ecd::2', '9936::', '5e5b::3', '3e10::2', 'a085::'];
        $set = IpRangeSet::fromEntries($addresses);

        foreach ($addresses as $address) {
            $this->assertTrue($set->contains($address), "{$address} deveria estar no conjunto");
        }

        foreach (['9eaa::3', 'ff44::', '1ecd::1', '9936::1', '5e5b::4', '3e10::', 'a085::1'] as $address) {
            $this->assertFalse($set->contains($address), "{$address} não deveria estar no conjunto");
        }
    }

    public function test_ipv4_mapped_ipv6_addresses_match_ipv4_ranges(): void
    {
        $set = IpRangeSet::fromEntries(['203.0.113.0/24']);

        $this->assertTrue($set->contains('::ffff:203.0.113.5'));
        $this->assertFalse($set->contains('::ffff:203.0.114.5'));
    }

    public function test_ranges_wider_than_the_minimum_prefix_are_ignored(): void
    {
        $set = IpRangeSet::fromEntries(
            ['0.0.0.0/0', '1.0.0.0/7', '::/0', '2001::/8', '3.0.0.0/8', '2600:1900::/28'],
            minIpv4Prefix: 8,
            minIpv6Prefix: 16,
        );

        $this->assertSame(4, $set->skipped);
        $this->assertSame(2, $set->count());
        $this->assertFalse($set->contains('8.8.8.8'));
        $this->assertTrue($set->contains('3.1.2.3'));
    }

    public function test_invalid_entries_are_skipped_and_counted(): void
    {
        $set = IpRangeSet::fromEntries([
            'not-an-ip',
            '1.2.3.4/33',
            '1.2.3.4/abc',
            '1.2.3/24',
            '',
            '203.0.113.9',
        ]);

        $this->assertSame(5, $set->skipped);
        $this->assertSame(1, $set->count());
    }

    public function test_invalid_query_never_matches(): void
    {
        $set = IpRangeSet::fromEntries(['203.0.113.0/24']);

        $this->assertFalse($set->contains('not-an-ip'));
        $this->assertFalse($set->contains(''));
    }

    public function test_an_empty_set_matches_nothing(): void
    {
        $this->assertTrue(IpRangeSet::empty()->isEmpty());
        $this->assertFalse(IpRangeSet::empty()->contains('203.0.113.9'));
    }

    public function test_it_survives_array_and_php_round_trips(): void
    {
        $set = IpRangeSet::fromEntries(['203.0.113.0/24', '198.51.100.9', '2001:2000::5', '2600:1900::/28']);

        $fromArray = IpRangeSet::fromArray($set->toArray());
        $fromPhp = IpRangeSet::fromArray(eval('return '.$set->toPhp().';'));

        foreach ([$fromArray, $fromPhp] as $copy) {
            $this->assertSame($set->toArray(), $copy->toArray());
            $this->assertTrue($copy->contains('203.0.113.200'));
            $this->assertTrue($copy->contains('198.51.100.9'));
            $this->assertTrue($copy->contains('2001:2000::5'));
            $this->assertTrue($copy->contains('2600:190f::1'));
            $this->assertFalse($copy->contains('198.51.100.10'));
        }
    }
}
