<?php

declare(strict_types=1);

namespace App\Video\ShotQa\Services;

use App\Models\VideoRender;
use App\Models\VideoShot;
use App\Video\ShotQa\Models\VideoShotRepair;
use RuntimeException;

final class ShotRepairService
{
    private const MAX_REPAIRS = 2;

    /** @param array<string, mixed> $repairPlan */
    public function recordPlan(VideoShot $shot, VideoRender $parentRender, array $repairPlan): VideoShotRepair
    {
        $repairIndex = (int) $repairPlan['repair_index'];

        if ($repairIndex > self::MAX_REPAIRS) {
            throw new RuntimeException('Shot repair loop limit exceeded.');
        }

        return VideoShotRepair::query()->firstOrCreate(
            ['repair_plan_hash' => (string) $repairPlan['repair_plan_hash']],
            [
                'video_shot_id' => $shot->id,
                'parent_render_execution_id' => $parentRender->id,
                'child_render_execution_id' => null,
                'qa_report_hash' => (string) $repairPlan['qa_report_hash'],
                'repair_index' => $repairIndex,
                'failure_signature' => (string) $repairPlan['failure_signature'],
                'mode' => (string) $repairPlan['mode'],
                'status' => 'planned',
                'repair_plan_json' => $repairPlan,
            ],
        );
    }
}
