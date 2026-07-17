<?php

namespace App\Video\Scene;

/**
 * Kết quả của Scene Planner: các scene ngữ nghĩa đã xếp thứ tự.
 *
 * Kiểu riêng, không phải "StoryGraph có thêm scene": SceneGraph ≠ StoryGraph ≠
 * RenderPlan. Mỗi tầng một trách nhiệm, đúng kỷ luật đã dùng ở Truth Layer
 * (CandidateWorldGraph ≠ VerifiedWorldGraph).
 */
final class SceneGraph
{
    /**
     * @param list<SemanticScene> $scenes đã sắp theo ordinal
     */
    public function __construct(
        public readonly array $scenes = [],
    ) {
    }
}
