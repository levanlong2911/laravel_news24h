<?php

namespace App\Video\Gatekeeper;

use App\Video\World\VerifiedWorldGraph;

/**
 * Kết quả gác cổng: sự thật sống sót + hồ sơ đầy đủ những gì bị loại và vì sao.
 */
final class GatekeeperReport
{
    /**
     * @param list<Rejection> $rejections
     */
    public function __construct(
        public readonly VerifiedWorldGraph $graph,
        public readonly array $rejections = [],
        public readonly int $candidateCount = 0,
    ) {
    }

    public function rejectedCount(): int
    {
        return count($this->rejections);
    }

    /**
     * Tỷ lệ giả thuyết sống sót. Chỉ để quan sát — không tầng nào được rẽ nhánh
     * theo con số này. Tụt đột ngột thường nghĩa là prompt của Extractor đã trôi
     * hoặc nhà cung cấp LLM đổi model dưới chân mình.
     */
    public function survivalRate(): float
    {
        return $this->candidateCount === 0
            ? 0.0
            : round(($this->candidateCount - $this->rejectedCount()) / $this->candidateCount, 4);
    }
}
