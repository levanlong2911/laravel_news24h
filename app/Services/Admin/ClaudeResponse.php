<?php

namespace App\Services\Admin;

final class ClaudeResponse
{
    public function __construct(
        public readonly string $text,
        public readonly int    $inputTokens,
        public readonly int    $outputTokens,
    ) {}
}
