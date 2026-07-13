<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS;

final class FilmOSError extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $layer,
        public readonly string $reason,
        public readonly bool   $recoverable = false,
        public readonly array  $metadata    = [],
        \Throwable             $previous    = null,
    ) {
        parent::__construct($reason, 0, $previous);
    }
}
