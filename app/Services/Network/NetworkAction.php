<?php

namespace App\Services\Network;

/**
 * Tratamento dado a um acesso, do mais brando ao mais rígido.
 */
enum NetworkAction: string
{
    case Allow = 'allow';
    case Evaluate = 'evaluate';
    case Restrict = 'restrict';
    case Block = 'block';

    public function severity(): int
    {
        return match ($this) {
            self::Allow => 0,
            self::Evaluate => 1,
            self::Restrict => 2,
            self::Block => 3,
        };
    }

    /**
     * Interpreta um valor de configuração; valores inválidos usam o padrão.
     */
    public static function fromConfig(mixed $value, self $fallback): self
    {
        return is_string($value)
            ? (self::tryFrom(strtolower(trim($value))) ?? $fallback)
            : $fallback;
    }

    /**
     * A mais branda entre esta ação e o teto informado.
     */
    public function cappedAt(self $cap): self
    {
        return $this->severity() > $cap->severity() ? $cap : $this;
    }

    public function isAtLeast(self $other): bool
    {
        return $this->severity() >= $other->severity();
    }
}
