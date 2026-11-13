<?php

namespace App\Services\Geo\Security;

/**
 * Posição enviada pelo navegador, ainda não confiável.
 */
final readonly class LocationSample
{
    /**
     * @param int|null $capturedAtMs Hora da leitura segundo o navegador
     *                               (position.timestamp, em ms). Pode ser forjada.
     * @param int      $receivedAtMs Hora em que o servidor recebeu a posição.
     */
    public function __construct(
        public float $latitude,
        public float $longitude,
        public float $accuracyMeters,
        public ?int $capturedAtMs,
        public int $receivedAtMs,
    ) {
    }
}
