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
    ) {
    }
}
