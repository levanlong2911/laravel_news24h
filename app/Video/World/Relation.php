<?php

namespace App\Video\World;

use App\Video\Evidence\Evidence;

/** Quan hệ đã verify — edge của World Graph. Story Planner dùng làm act COMPARISON. */
final class Relation
{
    public function __construct(
        public readonly string $id,
        public readonly string $from,
        public readonly string $to,
        public readonly string $type,
        public readonly Evidence $evidence,
    ) {
    }
}
