<?php

declare(strict_types=1);

namespace App\Video\ShotQa\Services;

use App\Models\VideoShot;
use App\Video\ShotQa\Models\VideoShotQaReport;

final class ShotQaReportStore
{
    /** @param array<string, mixed> $report */
    public function store(VideoShot $shot, array $report): VideoShotQaReport
    {
        return VideoShotQaReport::query()->firstOrCreate(
            ['report_hash' => (string) $report['report_hash']],
            [
                'video_shot_id' => $shot->id,
                'canonical_hash' => (string) $report['canonical_hash'],
                'identity_lock_hash' => (string) $report['identity_lock_hash'],
                'render_plan_hash' => (string) $report['render_plan_hash'],
                'scene_projection_hash' => (string) $report['scene_projection_hash'],
                'scene_state_graph_hash' => (string) $report['scene_state_graph_hash'],
                'motion_spec_hash' => (string) $report['motion_spec_hash'],
                'render_request_hash' => (string) $report['render_request_hash'],
                'artifact_hash' => (string) $report['artifact_hash'],
                'status' => (string) $report['status'],
                'failure_signature' => $report['failure_signature'] ?? null,
                'report_json' => $report,
            ],
        );
    }
}
