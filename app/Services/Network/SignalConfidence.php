<?php

namespace App\Services\Network;

/**
 * Quanto se pode confiar em um sinal de rede.
 *
 * A confiança limita a ação que um sinal pode causar sozinho: só fontes
 * oficiais ou conservadoras levam a bloqueio automático. Listas comunitárias,
 * heurísticas e dados colaborativos podem, no máximo, restringir ou observar.
 */
enum SignalConfidence: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public function maxAction(): NetworkAction
    {
        return match ($this) {
            self::Low => NetworkAction::Evaluate,
            self::Medium => NetworkAction::Restrict,
            self::High => NetworkAction::Block,
        };
    }

    public static function fromConfig(mixed $value, self $fallback = self::Low): self
    {
        return is_string($value)
            ? (self::tryFrom(strtolower(trim($value))) ?? $fallback)
            : $fallback;
    }

    public function rank(): int
    {
        return match ($this) {
            self::Low => 0,
            self::Medium => 1,
            self::High => 2,
        };
    }
}
