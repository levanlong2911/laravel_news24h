<?php

namespace App\Video\Timeline;

/** Kết quả Timeline Planner: các scene đã có [start, end], phủ kín target. */
final class TimedSceneGraph
{
    /**
     * @param list<TimedScene> $scenes
     */
    public function __construct(
        public readonly array $scenes = [],
        public readonly float $targetSeconds = 0.0,
    ) {
    }
}
