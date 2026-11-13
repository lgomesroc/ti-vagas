<?php

namespace App\Services\Geo\Security;

/**
 * Resultado da análise de uma posição enviada pelo visitante.
 */
final readonly class LocationAssessment
{
    /**
     * @param list<string> $reasons Sinais suspeitos encontrados.
     */
    public function __construct(
        public bool $allowed,
        public int $score,
        public array $reasons,
    ) {
    }
}
