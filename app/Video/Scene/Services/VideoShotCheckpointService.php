<?php

declare(strict_types=1);

namespace App\Video\Scene\Services;

use App\Models\VideoRender;
use App\Models\VideoShot;

final class VideoShotCheckpointService
{
    public function __construct(private readonly ShotIntentService $intents) {}

    public function markVideoReady(VideoShot $shot, VideoRender $render, string $motionSpecHash): VideoShot
    {
        return $this->intents->complete($shot, $render, $motionSpecHash);
    }
}
