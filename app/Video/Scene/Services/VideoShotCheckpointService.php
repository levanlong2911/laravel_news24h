<?php

declare(strict_types=1);

namespace App\Video\Scene\Services;

use App\Models\VideoRender;
use App\Models\VideoShot;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class VideoShotCheckpointService
{
    public function __construct(private readonly ShotIntentService $intents) {}

    public function markVideoReady(VideoShot $shot, VideoRender $render, string $motionSpecHash): VideoShot
    {
        return $this->intents->complete($shot, $render, $motionSpecHash);
    }

    public function commitState(VideoShot $shot): VideoShot
    {
        return DB::transaction(function () use ($shot): VideoShot {
            $shot = VideoShot::query()->whereKey($shot->id)->lockForUpdate()->firstOrFail();

            if ($shot->scene_status !== 'video_ready') {
                throw new RuntimeException('Scene state can only commit after video is ready.');
            }

            $shot->forceFill([
                'state_committed_at' => now(),
            ])->save();

            return $shot->refresh();
        }, attempts: 3);
    }
}
