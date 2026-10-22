<?php

namespace App\Video\Llm;

use App\Video\Concept\Claude\AnthropicStructuredOutputClient;

/**
 * Yêu cầu gửi tới mô hình.
 *
 * `instructionVersion` được version hoá có chủ ý: sáu tháng sau, khi truy một
 * hallucination, biết được lúc đó dùng instruction nào là khác biệt giữa "sửa
 * được" và "đoán mò".
 */
final class LlmRequest
{
    public function __construct(
        public readonly string $instruction,
        public readonly string $input,
        public readonly string $instructionVersion,
        public readonly string $model,
        public readonly int $maxTokens = 8192,
        /** `null` = không gửi, để mặc định của nhà cung cấp. Khác hẳn `0.0`. */
        public readonly ?float $temperature = null,
        public readonly ?string $effort = null,
        public readonly ?int $timeoutSeconds = null,
        public readonly ?int $maxAttempts = null,
    ) {
        if ($temperature !== null && (! is_finite($temperature) || $temperature < 0.0 || $temperature > 1.0)) {
            throw new \InvalidArgumentException('temperature must be null or a finite value in [0.0, 1.0]');
        }

        if ($effort !== null && ! in_array($effort, AnthropicStructuredOutputClient::EFFORTS, true)) {
            throw new \InvalidArgumentException('effort must be null or one of '.implode(', ', AnthropicStructuredOutputClient::EFFORTS));
        }
    }
}
