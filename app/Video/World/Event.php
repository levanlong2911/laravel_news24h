<?php

namespace App\Video\World;

use App\Video\Evidence\Evidence;

/** Sự kiện đã verify — node của World Graph. Story Planner dùng làm act EVENT. */
final class Event
{
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $entityId,
        public readonly Evidence $evidence,
    ) {
    }
}
