<?php

namespace Tests\Unit;

use App\Services\Geo\MunicipalityDirectory;
use App\Services\Geo\Security\LocationSample;
use App\Services\Geo\Security\LocationSpoofingDetector;
use PHPUnit\Framework\TestCase;

class LocationSpoofingDetectorTest extends TestCase
{
    private const NOW_MS = 1_800_000_000_000;

    private LocationSpoofingDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = new LocationSpoofingDetector(
            new MunicipalityDirectory(),
            require __DIR__ . '/../../config/location_guard.php'
        );
    }

    private function sample(
        float $latitude = -22.879143,
        float $longitude = -43.104271,
        float $accuracy = 23.4,
        ?int $capturedAtMs = null,
        ?int $receivedAtMs = null
    ): LocationSample {
        return new LocationSample(
            $latitude,
            $longitude,
            $accuracy,
            $capturedAtMs,
            $receivedAtMs ?? self::NOW_MS
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function niteroi(): array
    {
        return [
            'ibge' => '3303302',
            'uf' => 'RJ',
            'latitude' => -22.8833,
            'longitude' => -43.1036,
        ];
    }

    public function test_a_realistic_fix_has_no_signals(): void
    {
        $signals = $this->detector->detect(
            $this->sample(capturedAtMs: self::NOW_MS - 800),
            null,
            $this->niteroi(),
            'RJ'
        );

        $this->assertSame([], $signals);
    }

    public function test_zero_or_negative_accuracy_is_suspicious(): void
    {
        $this->assertArrayHasKey(
            'accuracy_zero',
            $this->detector->detect($this->sample(accuracy: 0.0))
        );

        $this->assertArrayHasKey(
            'accuracy_zero',
            $this->detector->detect($this->sample(accuracy: -5.0))
        );
    }

    public function test_implausibly_perfect_accuracy_is_a_weak_signal(): void
    {
        $signals = $this->detector->detect($this->sample(accuracy: 0.4));

        $this->assertSame(['accuracy_too_perfect' => 1], $signals);
    }

    public function test_round_coordinates_are_suspicious(): void
    {
        $this->assertArrayHasKey(
            'round_coordinates',
            $this->detector->detect($this->sample(-23.0, -43.0))
        );

        $this->assertArrayHasKey(
            'round_coordinates',
            $this->detector->detect($this->sample(-22.88, -43.1))
        );

        $this->assertArrayNotHasKey(
            'round_coordinates',
            $this->detector->detect($this->sample(-22.8791, -43.1042))
        );

        // Só uma das duas é redonda: não é suspeito.
        $this->assertArrayNotHasKey(
            'round_coordinates',
            $this->detector->detect($this->sample(-22.88, -43.104271))
        );
    }

    public function test_an_old_or_future_gps_timestamp_is_flagged_but_small_skew_is_not(): void
    {
        $this->assertArrayHasKey(
            'stale_or_skewed_timestamp',
            $this->detector->detect($this->sample(capturedAtMs: self::NOW_MS - 3_600_000))
        );

        $this->assertArrayHasKey(
            'stale_or_skewed_timestamp',
            $this->detector->detect($this->sample(capturedAtMs: self::NOW_MS + 3_600_000))
        );

        $this->assertArrayNotHasKey(
            'stale_or_skewed_timestamp',
            $this->detector->detect($this->sample(capturedAtMs: self::NOW_MS - 60_000))
        );

        // O navegador legado não envia a hora: não há o que comparar.
        $this->assertArrayNotHasKey(
            'stale_or_skewed_timestamp',
            $this->detector->detect($this->sample(capturedAtMs: null))
        );
    }

    public function test_impossible_travel_is_measured_with_the_servers_clock(): void
    {
        // Rio -> São Paulo (~360 km) em 2 minutos.
        $previous = [
            'latitude' => -22.9112,
            'longitude' => -43.1685,
            'at_ms' => self::NOW_MS - 120_000,
        ];

        $signals = $this->detector->detect(
            $this->sample(-23.5489, -46.6388),
            $previous
        );

        $this->assertArrayHasKey('impossible_travel', $signals);
    }

    public function test_fast_but_possible_travel_is_a_weak_signal(): void
    {
        // ~20 km em 3 minutos = ~400 km/h.
        $previous = [
            'latitude' => -22.9112,
            'longitude' => -43.1685,
            'at_ms' => self::NOW_MS - 180_000,
        ];

        $signals = $this->detector->detect(
            $this->sample(-22.7315, -43.1685),
            $previous
        );

        $this->assertArrayHasKey('fast_travel', $signals);
        $this->assertArrayNotHasKey('impossible_travel', $signals);
    }

    public function test_ordinary_travel_and_small_moves_are_not_flagged(): void
    {
        // ~30 km em 30 minutos.
        $driving = [
            'latitude' => -22.9112,
            'longitude' => -43.1685,
            'at_ms' => self::NOW_MS - 1_800_000,
        ];

        $this->assertSame(
            [],
            $this->detector->detect($this->sample(-22.6415, -43.1685), $driving)
        );

        // 1 km em 1 segundo: abaixo da distância mínima, é ruído de GPS.
        $jitter = [
            'latitude' => -22.8791,
            'longitude' => -43.1042,
            'at_ms' => self::NOW_MS - 1_000,
        ];

        $this->assertSame(
            [],
            $this->detector->detect($this->sample(-22.8701, -43.1042), $jitter)
        );
    }

    public function test_coordinates_equal_to_a_municipality_centroid_are_suspicious(): void
    {
        $signals = $this->detector->detect(
            $this->sample(-22.8833, -43.1036),
            null,
            $this->niteroi()
        );

        $this->assertSame(['centroid_exact' => 4], $signals);

        // 500 m do centroide: posição de quem está na cidade.
        $this->assertArrayNotHasKey(
            'centroid_exact',
            $this->detector->detect($this->sample(-22.8791, -43.1042), null, $this->niteroi())
        );
    }

    public function test_ip_state_mismatch_is_only_a_weak_signal(): void
    {
        $signals = $this->detector->detect(
            $this->sample(),
            null,
            $this->niteroi(),
            'SP'
        );

        $this->assertSame(['ip_state_mismatch' => 1], $signals);

        $this->assertSame(
            [],
            $this->detector->detect($this->sample(), null, $this->niteroi(), 'rj')
        );

        $this->assertSame(
            [],
            $this->detector->detect($this->sample(), null, $this->niteroi(), null)
        );
    }

    public function test_non_finite_numbers_are_rejected_outright(): void
    {
        $signals = $this->detector->detect($this->sample(NAN));

        $this->assertSame(['malformed_payload' => 4], $signals);

        $this->assertSame(
            ['malformed_payload' => 4],
            $this->detector->detect($this->sample(accuracy: INF))
        );
    }

    public function test_a_single_weak_signal_never_reaches_the_reject_threshold(): void
    {
        $config = require __DIR__ . '/../../config/location_guard.php';

        foreach (['accuracy_too_perfect', 'ip_state_mismatch', 'fast_travel', 'stale_or_skewed_timestamp', 'round_coordinates', 'accuracy_zero'] as $reason) {
            $this->assertLessThan(
                $config['reject_score'],
                $config['weights'][$reason],
                "{$reason} sozinho não pode recusar a posição."
            );
        }

        foreach (['impossible_travel', 'centroid_exact'] as $reason) {
            $this->assertGreaterThanOrEqual(
                $config['reject_score'],
                $config['weights'][$reason],
                "{$reason} é forte o bastante para recusar sozinho."
            );
        }
    }
}
