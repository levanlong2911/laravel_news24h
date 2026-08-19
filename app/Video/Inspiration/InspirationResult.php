<?php

namespace App\Video\Inspiration;

/**
 * Soi gương ConceptDesignResult: mang thêm nguyên văn phản hồi và số lượt đã thử.
 *
 * Trước đây `analyze()` trả thẳng InspirationBrief và VỨT `$response->text`. Hệ
 * quả đo được ngày 2026-08-18: ba lượt Haiku tốn $0.029512 mà không lượt nào để
 * lại gì đọc được — muốn biết vì sao một brief bị từ chối thì phải trả tiền chạy lại.
 */
final class InspirationResult
{
    public function __construct(
        public readonly InspirationBrief $brief,
        public readonly int $attempts,
        public readonly string $rawResponse = '',
    ) {}
}
