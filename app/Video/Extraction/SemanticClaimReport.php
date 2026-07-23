<?php

namespace App\Video\Extraction;

/**
 * Output của SemanticClaimPrecisionAnalyzer. Chỉ DATA.
 */
final class SemanticClaimReport
{
    /**
     * @param list<array{entity_id: string, attribute: string, value: mixed, evidence_quote: string, reason: string, confidence_reason: string}> $failures
     * @param array<string, int> $reasonDistribution B1.1 — LLM tự khai
     *        "verbatim"|"normalized"|"inferred" (hoặc '' nếu không khai), đếm
     *        tất cả semantic_claims (không phân biệt pass/fail) — CHỈ observability,
     *        không dùng để quyết định verify.
     */
    public function __construct(
        public readonly int $total,
        public readonly int $verified,
        public readonly array $failures,
        public readonly array $reasonDistribution = [],
    ) {
    }

    public function precision(): float
    {
        return $this->total > 0 ? $this->verified / $this->total : 0.0;
    }
}
