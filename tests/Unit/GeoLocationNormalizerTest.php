<?php

namespace Tests\Unit;

use App\Services\GeoLocationNormalizer;
use PHPUnit\Framework\TestCase;

class GeoLocationNormalizerTest extends TestCase
{
    private GeoLocationNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new GeoLocationNormalizer();
    }

    public function test_it_normalizes_brazilian_city_and_state(): void
    {
        $result = $this->normalizer->normalize([
            'city' => 'Niterói',
            'state' => 'RJ',
            'country' => 'Brasil',
        ]);

        $this->assertSame('Niterói', $result['city']);
        $this->assertSame('Rio de Janeiro', $result['state']);
        $this->assertSame('RJ', $result['state_code']);
        $this->assertSame('BR', $result['country_code']);
    }

    public function test_it_recognizes_state_name_without_accent(): void
    {
        $result = $this->normalizer->normalize([
            'city' => 'Sao Paulo',
            'state' => 'Sao Paulo',
        ]);

        $this->assertSame('São Paulo', $result['state']);
        $this->assertSame('SP', $result['state_code']);
    }

    public function test_it_normalizes_brazilian_country_codes_and_names(): void
    {
        $inputs = [
            ['country' => 'BR'],
            ['country' => 'Brasil'],
            ['country' => 'Brazil'],
            ['country_code' => 'br'],
        ];

        foreach ($inputs as $input) {
            $result = $this->normalizer->normalize($input);

            $this->assertSame('BR', $result['country_code']);
        }
    }

    public function test_it_rejects_coordinates_outside_valid_ranges(): void
    {
        $result = $this->normalizer->normalize([
            'latitude' => 91,
            'longitude' => -181,
        ]);

        $this->assertNull($result['latitude']);
        $this->assertNull($result['longitude']);
    }

    public function test_it_rejects_invalid_ibge_municipality_codes(): void
    {
        $result = $this->normalizer->normalize([
            'ibge_municipality_code' => '123456',
        ]);

        $this->assertNull($result['ibge_municipality_code']);
    }

    public function test_it_does_not_infer_missing_geographic_information(): void
    {
        $result = $this->normalizer->normalize([
            'location' => 'Niterói - RJ, Brasil',
        ]);

        $this->assertNull($result['city']);
        $this->assertNull($result['state']);
        $this->assertNull($result['state_code']);
        $this->assertNull($result['country_code']);
        $this->assertNull($result['ibge_municipality_code']);
        $this->assertNull($result['latitude']);
        $this->assertNull($result['longitude']);
    }
}
