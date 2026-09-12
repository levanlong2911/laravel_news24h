<?php

declare(strict_types=1);

namespace App\Video\Render\Qa;

use App\Models\RenderQaRun;
use App\Models\RenderRepair;
use App\Video\Render\Enums\QaDecision;

final class QaDecisionService
{
    public function nextAction(RenderQaRun $qaRun): string
    {
        if ($qaRun->decision === QaDecision::PASS) {
            return 'approve_candidate';
        }

        if ($qaRun->decision === QaDecision::REVIEW) {
            return 'human_review';
        }

        $repairCount = RenderRepair::query()
            ->where('parent_render_id', $qaRun->render_id)
            ->count();

        if ($repairCount >= 2) {
            return 'human_review';
        }

        if ($qaRun->repair_decision === 'targeted_repair') {
            return 'targeted_repair';
        }

        return 'human_review';
    }
}
