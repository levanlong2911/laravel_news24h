<?php

declare(strict_types=1);

namespace App\Video\Scene;

use RuntimeException;

final class ScenePlanException extends RuntimeException
{
    /** @param array<string, mixed> $usage */
    public function __construct(
        string $message,
        public readonly string $raw = '',
        public readonly array $usage = [],
    ) {
        parent::__construct($message);
    }
}
