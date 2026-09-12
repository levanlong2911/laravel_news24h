<?php

declare(strict_types=1);

namespace App\Video\Scene\Services;

use App\Models\VideoRender;
use App\Models\VideoShot;
use App\Video\Render\Enums\RenderStatus;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SceneImageCheckpointService
{
    public function markApproved(VideoShot $shot, VideoRender $render, string $sceneProjectionHash): VideoShot
    {
        return DB::transaction(function () use ($shot, $render, $sceneProjectionHash): VideoShot {
            $shot = VideoShot::query()->whereKey($shot->id)->lockForUpdate()->firstOrFail();
            $render = VideoRender::query()->whereKey($render->id)->firstOrFail();

            if ($render->execution_status !== RenderStatus::SUCCEEDED || $render->qa_approved !== true) {
                throw new RuntimeException('Scene image must succeed and pass QA.');
            }

            $shot->forceFill([
                'scene_image_render_id' => $render->id,
                'scene_projection_hash' => $sceneProjectionHash,
                'scene_status' => 'image_approved',
            ])->save();

            return $shot->refresh();
        }, attempts: 3);
    }
}
