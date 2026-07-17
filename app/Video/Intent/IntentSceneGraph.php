<?php

namespace App\Video\Intent;

/** Kết quả Intent Planner: các scene đã có ý đồ máy quay/chuyển động. */
final class IntentSceneGraph
{
    /**
     * @param list<IntentScene> $scenes
     */
    public function __construct(
        public readonly array $scenes = [],
    ) {
    }
}
