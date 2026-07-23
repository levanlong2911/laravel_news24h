<?php

namespace App\Video\Analysis;

/**
 * Output của ConfidenceAnalyzer. Chỉ DATA — không có method, không có công thức.
 */
final class ConfidenceReport
{
    /**
     * @param list<string> $coverageLayers layer có mặt (thứ tự cố định, xem ConfidenceAnalyzer::LAYERS)
     * @param list<string> $missingLayers  layer chưa có
     */
    public function __construct(
        public readonly float $coverageScore,
        public readonly array $coverageLayers,
        public readonly array $missingLayers,
        /**
         * Coverage tính TRÊN layer đã có code path thật (loại visual_style —
         * cố ý chưa xây, xem ConfidenceAnalyzer::IMPLEMENTED_LAYERS). Phản ánh
         * đúng tiến độ hơn coverageScore khi so sánh giữa các benchmark run.
         */
        public readonly float $implementedCoverageScore = 0.0,
    ) {
    }
}
