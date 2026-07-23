<?php

namespace App\Video\Extraction;

/**
 * Một giả thuyết do LLM đưa ra. CHƯA phải sự thật.
 *
 * Chú ý không có trường `offset`: LLM không được cấp offset, chỉ được trích
 * nguyên văn. Gatekeeper tự đi tìm. Xem ARCHITECTURE.md §11.
 */
final class CandidateClaim
{
    public function __construct(
        public readonly string $entityId,
        public readonly string $attribute,
        public readonly mixed $value,
        public readonly string $evidenceQuote,
        /**
         * CHỈ để observability. Gatekeeper KHÔNG dùng confidence để quyết định —
         * nó chỉ dùng Evidence. Một giả thuyết confidence 0.99 không có evidence
         * vẫn bị loại; confidence 0.4 có evidence vẫn được nhận.
         */
        public readonly float $confidence = 0.0,
        /**
         * B1.1 (2026-07-22, xem project_benchmark_pilot10_findings memory) —
         * LLM TỰ khai "verbatim"|"normalized"|"inferred". CHỈ để observability/
         * benchmark, giống $confidence — Gatekeeper/ValueVerifier KHÔNG đọc
         * field này để quyết định verify hay không, chỉ để thống kê phân loại
         * lỗi mà không cần đọc failures bằng mắt.
         */
        public readonly string $confidenceReason = '',
    ) {
    }
}
