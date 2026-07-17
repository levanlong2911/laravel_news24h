<?php

namespace App\Video\Story;

/**
 * Một act — một node|edge của World Graph được chọn để kể, kèm vai trò tự sự.
 *
 * Cố tình KHÔNG có: screenplay, narration, camera, prompt, emotion. Đó là việc
 * của Scene Planner và các tầng sau. Act chỉ trả lời "kể cái gì và với vai trò
 * gì", không trả lời "quay thế nào".
 *
 * Cũng KHÔNG có domain label (Luxury/Performance/Ownership) — xem NarrativeRole.
 */
final class Act
{
    public function __construct(
        public readonly string $id,
        public readonly int $ordinal,
        public readonly ActSource $source,
        public readonly string $sourceId,
        public readonly NarrativeRole $role,
        /** Graph centrality. So sánh được, không có nghĩa domain. */
        public readonly float $importance,
    ) {
    }
}
