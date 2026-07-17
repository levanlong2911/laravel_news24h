<?php

namespace App\Video\Story;

/**
 * Kết quả của Story Planner: các act đã xếp thứ tự.
 *
 * Nhỏ có chủ ý. Hiện chỉ có MỘT use case thật (Moonrise) — chưa đủ để sinh
 * NarrativeGraph, EmotionGraph hay bất cứ abstraction nào khác. Xem Rule 0.
 */
final class StoryGraph
{
    /**
     * @param list<Act> $acts đã sắp theo ordinal
     */
    public function __construct(
        public readonly array $acts = [],
    ) {
    }
}
