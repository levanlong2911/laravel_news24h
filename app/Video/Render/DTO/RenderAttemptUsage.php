<?php

declare(strict_types=1);

namespace App\Video\Render\DTO;

use InvalidArgumentException;

final class RenderAttemptUsage
{
    private const MONEY = '/^\d+(\.\d{1,8})?$/';

    public function __construct(
        public readonly ?int $inputTokens,
        public readonly ?int $outputTokens,
        public readonly ?int $imageInputTokens,
        public readonly ?int $textInputTokens,
        public readonly ?string $providerCostUsd,
    ) {
        if ($providerCostUsd !== null && preg_match(self::MONEY, $providerCostUsd) !== 1) {
            throw new InvalidArgumentException('provider_cost_usd khong phai so tien: '.$providerCostUsd);
        }
    }

    public static function fromArray(array $data): self
    {
        return new self(
            inputTokens: isset($data['input_tokens']) ? (int) $data['input_tokens'] : null,
            outputTokens: isset($data['output_tokens']) ? (int) $data['output_tokens'] : null,
            imageInputTokens: isset($data['image_input_tokens']) ? (int) $data['image_input_tokens'] : null,
            textInputTokens: isset($data['text_input_tokens']) ? (int) $data['text_input_tokens'] : null,
            providerCostUsd: self::money($data['provider_cost_usd'] ?? null),
        );
    }

    private static function money(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            if (! is_finite((float) $value) || $value < 0) {
                throw new InvalidArgumentException('provider_cost_usd khong hop le.');
            }

            return (string) $value;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('provider_cost_usd khong phai so tien: '.var_export($value, true));
        }

        return trim($value);
    }
}

