<?php

namespace App\Services\Network;

/**
 * Indício de que um IP pertence a determinado tipo de rede, com a fonte que
 * o apontou e o grau de confiança nela.
 */
final readonly class NetworkSignal
{
    public function __construct(
        public NetworkCategory $category,
        public string $source,
        public SignalConfidence $confidence,
    ) {}
}
