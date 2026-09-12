<?php

declare(strict_types=1);

namespace App\Video\ShotQa\Services;

use App\Models\VideoShot;
use App\Video\ShotQa\Models\VideoShotQaReport;
use DomainException;
use Illuminate\Support\Facades\DB;

final class ApproveVideoShot
{
    public function approve(VideoShot $shot, VideoShotQaReport $qa, int|string $adminId): void
    {
        if (! in_array($qa->status, ['PASS', 'PASS_WITH_WARNINGS'], true)) {
            throw new DomainException('Shot cannot be approved with QA status: '.$qa->status);
        }

        DB::transaction(function () use ($shot, $qa, $adminId): void {
            $locked = VideoShot::query()->lockForUpdate()->findOrFail($shot->getKey());

            if ($locked->approved_at !== null) {
                return;
            }

            $locked->forceFill([
                'approved_qa_report_id' => $qa->getKey(),
                'approved_artifact_hash' => $qa->artifact_hash,
                'approved_by_admin_id' => $adminId,
                'approved_at' => now(),
            ])->save();
        });
    }
}
