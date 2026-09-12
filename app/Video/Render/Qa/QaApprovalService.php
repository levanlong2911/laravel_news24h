<?php

declare(strict_types=1);

namespace App\Video\Render\Qa;

use App\Models\RenderQaRun;
use App\Models\VideoRender;
use App\Video\Render\Enums\QaDecision;

final class QaApprovalService
{
    public function markReadyForApproval(RenderQaRun $qaRun): void
    {
        if ($qaRun->decision !== QaDecision::PASS) {
            return;
        }

        VideoRender::query()
            ->whereKey($qaRun->render_id)
            ->update([
                'qa_status' => 'passed',
                'qa_approved' => true,
                'latest_qa_run_id' => $qaRun->id,
            ]);
    }
}
