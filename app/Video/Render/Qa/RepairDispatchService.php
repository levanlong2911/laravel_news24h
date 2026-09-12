<?php

declare(strict_types=1);

namespace App\Video\Render\Qa;

use App\Models\RenderRepair;
use App\Video\Render\Enums\QaRepairStatus;
use App\Video\Render\Qa\DTO\RepairDispatchPayload;

final class RepairDispatchService
{
    public function recordPlanned(RepairDispatchPayload $payload): RenderRepair
    {
        return RenderRepair::query()->firstOrCreate(
            [
                'qa_run_id' => $payload->qaRunId,
                'repair_scope_id' => $payload->repairScopeId,
            ],
            [
                'parent_render_id' => $payload->parentRenderId,
                'repair_generation' => $payload->repairGeneration,
                'status' => QaRepairStatus::PLANNED,
                'source_qa_report_hash' => $payload->sourceQaReportHash,
                'parent_request_hash' => $payload->parentRequestHash,
                'parent_artifact_hash' => $payload->parentArtifactHash,
                'repair_plan_json' => $payload->repairPlan,
                'repair_request_hash' => $payload->repairRequestHash,
            ],
        );
    }

    public function markDispatched(RenderRepair $repair, string $repairRenderId, string $repairRequestHash): RenderRepair
    {
        $repair->forceFill([
            'repair_render_id' => $repairRenderId,
            'repair_request_hash' => $repairRequestHash,
            'status' => QaRepairStatus::DISPATCHED,
        ])->save();

        return $repair;
    }
}
