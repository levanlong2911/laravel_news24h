<?php

declare(strict_types=1);

namespace App\Video\Scene\Services;

use App\Models\VideoShot;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class KeyframeCheckpointService
{
    public function markReady(VideoShot $shot): VideoShot
    {
        return DB::transaction(function () use ($shot): VideoShot {
            $shot = VideoShot::query()->whereKey($shot->id)->lockForUpdate()->firstOrFail();

            if (! is_string($shot->scene_image_render_id) || $shot->scene_image_render_id === '') {
                throw new RuntimeException('Scene image must be approved before keyframe.');
            }

            $shot->forceFill([
                'scene_status' => 'keyframe_ready',
            ])->save();

            return $shot->refresh();
        }, attempts: 3);
    }
}
