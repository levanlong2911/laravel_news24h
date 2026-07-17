<?php

namespace App\Video\Llm;

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
        /** 0.0 — trích xuất cần ổn định, không cần sáng tạo. */
        public readonly float $temperature = 0.0,
    ) {
    }

    /** Ước lượng thô để hiện chi phí trước khi xin duyệt. ~4 ký tự/token. */
    public function estimatedInputTokens(): int
    {
        return (int) ceil((mb_strlen($this->instruction) + mb_strlen($this->input)) / 4);
    }
}
