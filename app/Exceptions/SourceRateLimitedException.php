<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Limitação temporária imposta pela fonte (por exemplo, HTTP 429).
 *
 * Não representa falha definitiva: quem captura deve interromper a coleta
 * e reagendá-la para depois, sem insistir imediatamente.
 */
class SourceRateLimitedException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $retryAfterSeconds = null,
        public readonly string $source = 'linkedin-jobs'
    ) {
        parent::__construct($message);
    }
}
