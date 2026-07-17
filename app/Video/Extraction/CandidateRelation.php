<?php

namespace App\Video\Extraction;

/**
 * Giả thuyết về một quan hệ.
 *
 * `successor_of` chỉ được tồn tại nếu bài báo THẬT SỰ nói vậy ("successor",
 * "replaces", "based on", "updated from"). LLM tự suy ra quan hệ từ việc hai
 * entity cùng xuất hiện → Reject.
 */
final class CandidateRelation
{
    public function __construct(
        public readonly string $id,
        public readonly string $from,
        public readonly string $to,
        public readonly string $type,
        public readonly string $evidenceQuote,
        public readonly float $confidence = 0.0,
    ) {
    }
}
