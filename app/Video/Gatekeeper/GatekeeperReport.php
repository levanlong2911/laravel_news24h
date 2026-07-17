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

    /** @return list<Rejection> */
    public function rejectionsFor(RejectionReason $reason): array
    {
        return array_values(array_filter($this->rejections, fn (Rejection $r) => $r->reason === $reason));
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

    public function summary(): string
    {
        return sprintf(
            '%d/%d giả thuyết được verify (%.1f%%), %d bị loại',
            $this->candidateCount - $this->rejectedCount(),
            $this->candidateCount,
            $this->survivalRate() * 100,
            $this->rejectedCount(),
        );
    }
}
