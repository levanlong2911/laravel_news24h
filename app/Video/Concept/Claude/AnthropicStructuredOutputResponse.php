<?php

declare(strict_types=1);

namespace App\Video\Concept\Claude;

final class AnthropicStructuredOutputResponse
{
    public function __construct(
        public readonly string $rawText,
        public readonly string $model,
        public readonly ?string $stopReason,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly ?string $requestId = null,
        /** @var bool false when the final usage never arrived: the tokens are a lower bound */
        public readonly bool $usageComplete = true,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function usage(): array
    {
        return [
            'input_tokens' => $this->inputTokens,

            'output_tokens' => $this->outputTokens,
        ];
    }
}
