<?php

namespace App\Video\Timeline;

use App\Video\Intent\IntentScene;

/** Một IntentScene đã được đặt vào dòng thời gian. Refinement, không type song song. */
final class TimedScene
{
    public function __construct(
        public readonly IntentScene $intent,
        public readonly TimeRange $time,
    ) {
    }
}
