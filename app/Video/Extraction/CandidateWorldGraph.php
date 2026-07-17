<?php

namespace App\Video\Extraction;

/**
 * Toàn bộ output của LLM Extractor. Đây là GIẢ THUYẾT, không phải sự thật.
 *
 * Không tầng nào ngoài EvidenceGatekeeper được phép đọc object này. Story
 * Planner nhận VerifiedWorldGraph — kiểu khác hẳn — nên việc lỡ tay dùng giả
 * thuyết như sự thật là lỗi biên dịch, không phải lỗi runtime im lặng.
 */
final class CandidateWorldGraph
{
    /**
     * @param list<CandidateEntity>   $entities
     * @param list<CandidateRelation> $relations
     * @param list<CandidateEvent>    $events
     */
    public function __construct(
        public readonly array $entities = [],
        public readonly array $relations = [],
        public readonly array $events = [],
    ) {
    }
}
