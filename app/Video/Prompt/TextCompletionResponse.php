<?php

declare(strict_types=1);

namespace App\Video\Prompt;

final class TextCompletionResponse
{
    public function __construct(
        public readonly string $text,
        public readonly string $model,
        public readonly string $stopReason,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly ?string $requestId = null,
        public readonly int $reasoningTokens = 0,
    ) {}

    public function wasTruncated(): bool
    {
        return $this->stopReason === 'max_tokens';
    }
}
