<?php

declare(strict_types=1);

namespace App\Video\Scene;

final class ScenePlanResult
{
    /** @param list<array<string, mixed>> $scenes */
    public function __construct(
        public readonly array $scenes,
        public readonly string $raw,
        public readonly string $authorModel,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $reasoningTokens,
    ) {}
}
