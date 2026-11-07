<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\DesignImageStatus;
use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoArtifact;
use App\Models\VideoDesignImage;
use App\Models\VideoPlanningStage;
use App\Video\DesignDna\DnaCheck;
use App\Video\YachtDesign\DesignRunPolicy;
use App\Video\YachtDesign\YachtDesignCheck;

/**
 * Whether the design of a project was approved with its model sheet, read from the rows alone.
 *
 * It reads no file: a master lost from disk is a problem of that resource and never reopens the
 * design. It calls neither the design panel nor the model sheet context, since those ask it in turn.
 */
final class DesignApprovalLock
{
    public const MESSAGE = 'Bản thiết kế đã duyệt cùng model sheet — không tạo Design hoặc DNA mới.';

    public function __construct(private readonly PlanningStageStore $stageStore) {}

    /** @return ?VideoPlanningStage the newest succeeded design, the one the panel shows and the model sheet works from */
    public function currentDesign(string $projectId): ?VideoPlanningStage
    {
        return VideoPlanningStage::query()->withoutSnapshot()
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::YACHT_DESIGN->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->whereNull('input_json->orphan_of_stage_id')
            ->orderByDesc('planning_revision')
            ->first();
    }

    public function holds(string $projectId): bool
    {
        $design = $this->currentDesign($projectId);

        return $design !== null && $this->approves($projectId, $design);
    }

    /** @return bool an approved master of this design, its set, its selected artifact, the canonical hash they pinned and the review that approved them all agree */
    public function approves(string $projectId, VideoPlanningStage $design): bool
    {
        $canonicalHash = ModelSheetService::designHash($design, (string) ($design->input_json['workflow'] ?? ''));

        if (! is_string($canonicalHash) || $canonicalHash === '') {
            return false;
        }

        $images = VideoDesignImage::query()
            ->where('project_id', $projectId)
            ->where('image_type', DesignImageStore::MODEL_SHEET_MASTER_TYPE)
            ->where('status', DesignImageStatus::APPROVED->value)
            ->whereNotNull('selected_artifact_id')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(prompt_spec_json, '$.design_stage_id')) = ?", [(string) $design->id])
            ->get();

        foreach ($images as $image) {
            $spec = (array) $image->prompt_spec_json;
            $set = VideoPlanningStage::query()->withoutSnapshot()->whereKey($spec['model_sheet_set_id'] ?? null)->where('project_id', $projectId)
                ->where('stage', PlanningStageName::MODEL_SHEET->value)->where('status', VideoPlanningStageStatus::SUCCEEDED->value)->first();
            $artifact = VideoArtifact::query()->whereKey($image->selected_artifact_id)->where('project_id', $projectId)->first();

            if ($set === null || $artifact === null || ($set->input_json['design_stage_id'] ?? null) !== (string) $design->id
                || ($spec['design_canonical_hash'] ?? null) !== $canonicalHash) {
                continue;
            }

            if ($this->stageStore->latestSucceededForInput($projectId, PlanningStageName::MODEL_SHEET_REVIEW, ModelSheetService::reviewInput($set, $image, $artifact)) !== null) {
                return true;
            }
        }

        return false;
    }

    /** @return ?string why a stored design cannot be trusted: its own records, and the DNA it says it was written from */
    public function integrityProblem(string $projectId, VideoPlanningStage $design, string $workflow): ?string
    {
        $problem = DesignRunPolicy::integrityProblem($design, $workflow);

        if ($problem !== null) {
            return $problem;
        }

        $dna = $design->input_json['dna_stage_id'] ?? null;

        return is_string($dna) && ! VideoPlanningStage::query()->whereKey($dna)->where('project_id', $projectId)
            ->where('stage', PlanningStageName::DESIGN_DNA->value)->where('status', VideoPlanningStageStatus::SUCCEEDED->value)->exists()
                ? 'Không tìm thấy lượt DNA nguồn của bản thiết kế này.'
                : null;
    }

    /**
     * @return ?string why this design can no longer be the source of an approval, read from the rows
     *                 as they are now: call it under the project lock, never from a panel kept earlier
     */
    public function sourceProblem(string $projectId, VideoPlanningStage $design, string $workflow): ?string
    {
        $problem = $this->integrityProblem($projectId, $design, $workflow);

        if ($problem !== null) {
            return $problem;
        }

        if (($design->output_json['check']['verdict'] ?? null) !== YachtDesignCheck::PASS) {
            return 'Bản thiết kế nguồn chưa đạt kiểm tự động.';
        }

        $pinned = $design->input_json['dna_stage_id'] ?? null;
        $newest = VideoPlanningStage::query()
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::DESIGN_DNA->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->where('output_json->check->verdict', DnaCheck::PASS)
            ->whereNull('input_json->orphan_of_stage_id')
            ->orderByDesc('planning_revision')
            ->value('id');

        return is_string($pinned) && $newest !== null && (string) $newest !== $pinned
            ? 'Design DNA đã đổi so với bản thiết kế này.'
            : null;
    }

    /** @return ?VideoPlanningStage a Design or DNA run still waiting or holding its claim, stuck ones too: each can still change the source until someone settles it */
    public function activeRun(string $projectId): ?VideoPlanningStage
    {
        return VideoPlanningStage::query()->withoutSnapshot()
            ->where('project_id', $projectId)
            ->whereIn('stage', [PlanningStageName::YACHT_DESIGN->value, PlanningStageName::DESIGN_DNA->value])
            ->whereIn('status', [VideoPlanningStageStatus::PENDING->value, VideoPlanningStageStatus::RUNNING->value])
            ->whereNotNull('claim_token')
            ->orderByDesc('planning_revision')
            ->first();
    }
}
