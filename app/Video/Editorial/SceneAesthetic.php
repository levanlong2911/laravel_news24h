<?php

namespace App\Video\Editorial;

/**
 * Gu thẩm mỹ cho một scene — Editorial taste, KHÔNG phải world fact.
 *
 * Luôn đầy đủ 4 trường: Editorial luôn điền default thẩm mỹ. Đối lập với
 * `world{}` (fact) được phép vắng. Xem §13.
 */
final class SceneAesthetic
{
    public function __construct(
        public readonly Emotion $emotion,
        public readonly Composition $composition,
        public readonly LightIntensity $lightIntensity,
        public readonly LightGrade $lightGrade,
    ) {
    }
}
