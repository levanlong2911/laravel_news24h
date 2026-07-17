<?php

namespace App\Video\Llm;

/**
 * Ném ra khi một cú gọi TỐN PHÍ chưa được duyệt.
 *
 * Không có ngoại lệ. Mặc định là từ chối: một pipeline lỡ tay chạy trên 500 bài
 * báo sẽ đốt tiền thật, và nó sẽ làm điều đó rất im lặng.
 */
final class ApprovalRequired extends \RuntimeException
{
    public function __construct(
        public readonly string $what,
        public readonly float $estimatedCostUsd,
        public readonly int $estimatedTokens,
    ) {
        parent::__construct(sprintf(
            '%s cần duyệt trước: ~%d token, ~$%.4f. Cấp một ApprovalGate cho phép cú gọi này.',
            $what,
            $estimatedTokens,
            $estimatedCostUsd,
        ));
    }
}
