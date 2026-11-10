<?php

namespace App\Services\Geo;

/**
 * Região de atuação de um visitante.
 *
 * - unit: RM ou RIDE do município do visitante;
 * - intermediate: Região Geográfica Intermediária, apenas com municípios
 *   que não pertencem a RM ou RIDE;
 * - municipality: somente o próprio município (quando não há dados
 *   regionais disponíveis).
 */
final class RegionScope
{
    public const UNIT = 'unit';
    public const INTERMEDIATE = 'intermediate';
    public const MUNICIPALITY = 'municipality';

    public function __construct(
        public readonly string $type,
        public readonly string $key,
        public readonly string $label
    ) {
    }

    /**
     * @param array<string, mixed> $municipality
     */
    public function includes(array $municipality): bool
    {
        return match ($this->type) {
            self::UNIT => ($municipality['region_code'] ?? null) === $this->key,
            self::INTERMEDIATE => ($municipality['region_code'] ?? null) === null
                && ($municipality['intermediate_region_code'] ?? null) === $this->key,
            self::MUNICIPALITY => ($municipality['ibge'] ?? null) === $this->key,
            default => false,
        };
    }
}
