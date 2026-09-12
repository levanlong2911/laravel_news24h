<?php

declare(strict_types=1);

namespace App\Video\ShotQa\Services;

use App\Models\VideoRender;
use App\Models\VideoShot;
use App\Video\ShotQa\Models\ApprovedShotRevision;
use App\Video\ShotQa\Models\VideoShotQaReport;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ApprovedShotRevisionService
{
    public function freeze(VideoShot $shot, VideoRender $render, VideoShotQaReport $qa): ApprovedShotRevision
    {
        return DB::transaction(function () use ($shot, $render, $qa): ApprovedShotRevision {
            $shot = VideoShot::query()->whereKey($shot->id)->lockForUpdate()->firstOrFail();

            if ($shot->approved_qa_report_id !== $qa->id) {
                throw new RuntimeException('Shot QA report is not approved for this shot.');
            }

            $revision = ((int) ApprovedShotRevision::query()
                ->where('video_shot_id', $shot->id)
                ->max('revision')) + 1;

            return ApprovedShotRevision::query()->create([
                'video_shot_id' => $shot->id,
                'shot_code' => $shot->shot_code,
                'revision' => $revision,
                'canonical_hash' => $qa->canonical_hash,
                'identity_lock_hash' => $qa->identity_lock_hash,
                'render_plan_hash' => $qa->render_plan_hash,
                'scene_projection_hash' => $qa->scene_projection_hash,
                'scene_state_graph_hash' => $qa->scene_state_graph_hash,
                'motion_spec_hash' => $qa->motion_spec_hash,
                'render_request_hash' => $qa->render_request_hash,
                'artifact_hash' => $qa->artifact_hash,
                'qa_report_hash' => $qa->report_hash,
                'provider' => $render->provider,
                'provider_model' => $render->model,
                'status' => 'FROZEN',
                'frozen_at' => now(),
            ]);
        }, attempts: 3);
    }
}
