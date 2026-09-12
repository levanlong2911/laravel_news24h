<?php

declare(strict_types=1);

namespace App\Video\Scene;

final class ScenePlanReviewResult
{
    /**
     * @param  list<array<string, mixed>>  $findings
     * @param  list<array<string, mixed>>  $patch
     */
    public function __construct(
        public readonly string $verdict,
        public readonly array $findings,
        public readonly array $patch,
        public readonly string $raw,
        public readonly string $reviewerModel,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $reasoningTokens,
    ) {}
}
