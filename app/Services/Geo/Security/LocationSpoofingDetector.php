<?php

namespace App\Services\Geo\Security;

use App\Services\Geo\MunicipalityDirectory;

/**
 * Procura sinais de que a posição enviada pelo navegador pode ser falsa.
 *
 * São heurísticas: um sinal isolado quase nunca basta para recusar a posição
 * e nenhuma combinação prova que o GPS é verdadeiro. Quem quiser simular uma
 * posição plausível, com ruído realista e deslocamento coerente, consegue.
 * O objetivo é barrar o abuso simples, caro de repetir e fácil de registrar.
 */
class LocationSpoofingDetector
{
    /** @var array<string, mixed> */
    private readonly array $config;

    /**
     * @param array<string, mixed>|null $config Padrão: config('location_guard').
     */
    public function __construct(
        private readonly MunicipalityDirectory $directory,
        ?array $config = null
    ) {
        $this->config = $config ?? (array) config('location_guard', []);
    }

    /**
     * @param array{latitude: float, longitude: float, at_ms: int}|null $previousFix
     *        Última posição aceita nesta sessão.
     * @param array<string, mixed>|null $municipality
     *        Município resolvido para a posição (para checar o centroide).
     * @param string|null $ipStateCode UF estimada pelo IP, se conhecida.
     * @return array<string, int> motivo => peso
     */
    public function detect(
        LocationSample $sample,
        ?array $previousFix = null,
        ?array $municipality = null,
        ?string $ipStateCode = null
    ): array {
        $signals = [];

        if (
            !is_finite($sample->latitude)
            || !is_finite($sample->longitude)
            || !is_finite($sample->accuracyMeters)
        ) {
            // Valores não numéricos nunca vêm de um GPS: recusa direta.
            return ['malformed_payload' => $this->reject()];
        }

        if ($sample->accuracyMeters <= 0.0) {
            $signals['accuracy_zero'] = $this->weight('accuracy_zero');
        } elseif ($sample->accuracyMeters < (float) $this->setting('min_plausible_accuracy_meters', 1.0)) {
            $signals['accuracy_too_perfect'] = $this->weight('accuracy_too_perfect');
        }

        if (
            $this->decimals($sample->latitude) <= 2
            && $this->decimals($sample->longitude) <= 2
        ) {
            $signals['round_coordinates'] = $this->weight('round_coordinates');
        }

        if ($sample->capturedAtMs !== null) {
            $skewSeconds = abs($sample->receivedAtMs - $sample->capturedAtMs) / 1000;

            if ($skewSeconds > (float) $this->setting('max_clock_skew_seconds', 300)) {
                $signals['stale_or_skewed_timestamp'] = $this->weight('stale_or_skewed_timestamp');
            }
        }

        if ($previousFix !== null) {
            $signals += $this->travelSignals($sample, $previousFix);
        }

        if ($municipality !== null) {
            $meters = $this->directory->distanceToKm(
                $municipality,
                $sample->latitude,
                $sample->longitude
            ) * 1000;

            if ($meters <= (float) $this->setting('centroid_exact_meters', 15.0)) {
                $signals['centroid_exact'] = $this->weight('centroid_exact');
            }

            if (
                $ipStateCode !== null
                && strtoupper($ipStateCode) !== strtoupper((string) ($municipality['uf'] ?? ''))
            ) {
                $signals['ip_state_mismatch'] = $this->weight('ip_state_mismatch');
            }
        }

        return array_filter($signals, static fn (int $weight): bool => $weight > 0);
    }

    /**
     * @param array{latitude: float, longitude: float, at_ms: int} $previousFix
     * @return array<string, int>
     */
    private function travelSignals(LocationSample $sample, array $previousFix): array
    {
        $km = $this->directory->distanceKm(
            $previousFix['latitude'],
            $previousFix['longitude'],
            $sample->latitude,
            $sample->longitude
        );

        if ($km < (float) $this->setting('min_travel_km', 5.0)) {
            return [];
        }

        // Tempo medido pelo servidor: o navegador não pode encurtá-lo.
        $hours = max(1000, $sample->receivedAtMs - $previousFix['at_ms']) / 3_600_000;
        $speed = $km / $hours;

        if ($speed > (float) $this->setting('impossible_speed_kmh', 1000.0)) {
            return ['impossible_travel' => $this->weight('impossible_travel')];
        }

        if ($speed > (float) $this->setting('suspicious_speed_kmh', 300.0)) {
            return ['fast_travel' => $this->weight('fast_travel')];
        }

        return [];
    }

    /**
     * Casas decimais significativas do valor (até 8).
     */
    private function decimals(float $value): int
    {
        $text = rtrim(rtrim(sprintf('%.8F', abs($value)), '0'), '.');
        $dot = strpos($text, '.');

        return $dot === false ? 0 : strlen($text) - $dot - 1;
    }

    private function weight(string $reason): int
    {
        return (int) ($this->config['weights'][$reason] ?? 0);
    }

    private function reject(): int
    {
        return max(1, (int) $this->setting('reject_score', 4));
    }

    private function setting(string $key, mixed $default): mixed
    {
        return $this->config[$key] ?? $default;
    }
}
