<?php

namespace App\Video\Llm;

final class LlmResponse
{
    public function __construct(
        public readonly string $text,
        public readonly string $model,
        public readonly int $tokensIn = 0,
        public readonly int $tokensOut = 0,
        public readonly int $latencyMs = 0,
        public readonly float $costUsd = 0.0,
        /** Nguyên văn phản hồi. Giữ lại để truy hallucination sau này. */
        public readonly string $raw = '',
        public readonly string $providerModel = '',
        public readonly ?int $thinkingTokens = null,
        /** @var bool false when the final usage never arrived: tokens and cost are what was known, a lower bound */
        public readonly bool $usageComplete = true,
    ) {
    }
}
