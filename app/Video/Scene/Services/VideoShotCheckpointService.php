<?php

declare(strict_types=1);

namespace App\Video\Scene\Services;

use App\Models\VideoRender;
use App\Models\VideoShot;
use App\Video\Render\Enums\RenderStatus;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class VideoShotCheckpointService
{
    public function markVideoReady(VideoShot $shot, VideoRender $render, string $motionSpecHash): VideoShot
    {
        return DB::transaction(function () use ($shot, $render, $motionSpecHash): VideoShot {
            $shot = VideoShot::query()->whereKey($shot->id)->lockForUpdate()->firstOrFail();
            $render = VideoRender::query()->whereKey($render->id)->firstOrFail();

            if ($render->execution_status !== RenderStatus::SUCCEEDED) {
                throw new RuntimeException('Video render must succeed before shot is ready.');
            }

            $shot->forceFill([
                'video_render_id' => $render->id,
                'motion_spec_hash' => $motionSpecHash,
                'scene_status' => 'video_ready',
            ])->save();

            return $shot->refresh();
        }, attempts: 3);
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
