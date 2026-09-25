<?php

declare(strict_types=1);

namespace App\Video\Screenplay;

use RuntimeException;

final class ScreenplayFailure extends RuntimeException
{
    /** @param array<string, mixed> $usage */
    public function __construct(
        string $message,
        public readonly string $rawResponse = '',
        public readonly array $usage = [],
    ) {
        parent::__construct($message);
    }
}
