<?php

declare(strict_types=1);

namespace App\Video\Render\DTO;

final class RenderAttemptUsage
{
    public function __construct(
        public readonly ?int $inputTokens,
        public readonly ?int $outputTokens,
        public readonly ?int $imageInputTokens,
        public readonly ?int $textInputTokens,
        public readonly ?string $providerCostUsd,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            inputTokens: isset($data['input_tokens']) ? (int) $data['input_tokens'] : null,
            outputTokens: isset($data['output_tokens']) ? (int) $data['output_tokens'] : null,
            imageInputTokens: isset($data['image_input_tokens']) ? (int) $data['image_input_tokens'] : null,
            textInputTokens: isset($data['text_input_tokens']) ? (int) $data['text_input_tokens'] : null,
            providerCostUsd: isset($data['provider_cost_usd']) ? (string) $data['provider_cost_usd'] : null,
        );
    }
}

