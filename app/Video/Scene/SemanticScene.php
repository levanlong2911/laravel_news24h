<?php

namespace App\Video\Scene;

/**
 * Một scene ở tầng NGỮ NGHĨA — hoàn chỉnh như chính nó, KHÔNG phải mảnh
 * RenderPlan bị khuyết.
 *
 * Cố tình KHÔNG mang: camera, lighting, physics, motion (→ Intent, Phase 3),
 * emotion (→ Editorial, Phase 5 — "vì sao cảnh này hùng vĩ" là gu đạo diễn,
 * không phải sự thật), continuity (→ Phase 5, tính từ World Graph + Editorial).
 *
 * SemanticScene chỉ trả lời: "act này thành mấy cảnh, mỗi cảnh về entity nào,
 * với chức năng tự sự gì". Không trả lời "quay thế nào".
 */
final class SemanticScene
{
    /**
     * @param list<string> $subjectIds entity của World Graph mà scene này khắc hoạ
     */
    public function __construct(
        public readonly string $id,
        public readonly string $actId,
        public readonly int $ordinal,
        public readonly ScenePurpose $purpose,
        public readonly array $subjectIds,
    ) {
    }
}
