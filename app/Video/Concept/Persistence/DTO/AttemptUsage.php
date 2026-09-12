<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\DTO;

final class AttemptUsage
{
    public function __construct(
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $thinkingTokens = 0,
        public readonly float $costUsd = 0.0,
        public readonly ?int $latencyMs = null,
        public readonly ?string $providerRequestId = null,
        public readonly ?string $stopReason = null,
    ) {}

    public static function empty(): self
    {
        return new self;
    }
}
