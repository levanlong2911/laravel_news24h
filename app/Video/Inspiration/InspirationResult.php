<?php

namespace App\Video\Inspiration;

final class InspirationResult
{
    /** @param array<string, mixed> $usage */
    public function __construct(
        public readonly string $rawResponse,
        public readonly array $usage,
    ) {}
}
