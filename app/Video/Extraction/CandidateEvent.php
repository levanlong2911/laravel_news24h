<?php

namespace App\Video\Extraction;

/**
 * Giả thuyết về một sự kiện.
 *
 * Event `construction` KHÔNG sinh ra vì entity là `vehicle` — mà vì bài báo có
 * "built" / "construction" / "shipyard" / "delivered" / "launched". Suy từ loại
 * entity ra sự kiện là suy luận, không phải trích xuất.
 */
final class CandidateEvent
{
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $entityId,
        public readonly string $evidenceQuote,
        public readonly float $confidence = 0.0,
    ) {
    }
}
