<?php

namespace App\Video\Extraction;

/** Giả thuyết về một entity. Kiểu này KHÁC App\Video\World\Entity có chủ ý. */
final class CandidateEntity
{
    /**
     * @param list<CandidateClaim> $claims
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly array $claims = [],
        public readonly ?string $name = null,
        public readonly string $nameQuote = '',
        public readonly float $confidence = 0.0,
    ) {
    }
}
