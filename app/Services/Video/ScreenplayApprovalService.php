<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Models\VideoReviewDecision;
use App\Video\Screenplay\ScreenplayContentHash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ScreenplayApprovalService
{
    public const ENTITY_TYPE = 'screenplay';

    private function productionContract(): string
    {
        return (string) config('video.screenplay.scenes.assembled_version', 'screenplay_v4');
    }

    /**
     * @return array{0: ?VideoReviewDecision, 1: string}
     */
    public function approve(
        string $projectId,
        string $stageId,
        ?string $reviewerId,
        string $operationId,
        ?string $reason = null,
    ): array {
        if (! Str::isUuid($operationId)) {
            return [null, 'approval_operation_invalid'];
        }

        return DB::transaction(function () use ($projectId, $stageId, $reviewerId, $operationId, $reason): array {
            $project = VideoProject::query()->whereKey($projectId)->lockForUpdate()->first();

            if ($project === null) {
                return [null, 'project_not_found'];
            }

            $stage = VideoPlanningStage::query()
                ->whereKey($stageId)
                ->where('project_id', $projectId)
                ->where('stage', PlanningStageName::SCREENPLAY->value)
                ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
                ->first();

            if ($stage === null || ! is_array($stage->output_json)) {
                return [null, 'screenplay_not_approvable'];
            }

            $contract = (string) ($stage->output_json['schema_version'] ?? '');

            if ($contract !== $this->productionContract()) {
                return [null, 'screenplay_not_approvable'];
            }

            $contentHash = ScreenplayContentHash::of($stage->output_json);
            $payloadHash = hash('sha256', json_encode([
                'project_id' => $projectId,
                'stage_id' => $stageId,
                'decision' => 'approved',
                'content_hash' => $contentHash,
                'contract_version' => $contract,
                'reason' => $reason,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $replayed = VideoReviewDecision::query()
                ->where('project_id', $projectId)
                ->where('entity_type', self::ENTITY_TYPE)
                ->where('metadata_json->operation_id', $operationId)
                ->first();

            if ($replayed !== null) {
                return hash_equals((string) ($replayed->metadata_json['operation_payload_hash'] ?? ''), $payloadHash)
                    ? [$replayed, 'replayed']
                    : [null, 'approval_operation_conflict'];
            }

            $revision = 1 + (int) VideoReviewDecision::query()
                ->where('entity_type', self::ENTITY_TYPE)
                ->where('entity_id', $stageId)
                ->max('revision');

            return [VideoReviewDecision::create([
                'project_id' => $projectId,
                'session_id' => null,
                'entity_type' => self::ENTITY_TYPE,
                'entity_id' => $stageId,
                'decision' => 'approved',
                'revision' => $revision,
                'reviewer_id' => $reviewerId,
                'reason' => $reason,
                'metadata_json' => [
                    'operation_id' => $operationId,
                    'operation_payload_hash' => $payloadHash,
                    'content_hash' => $contentHash,
                    'contract_version' => $contract,
                    'planning_revision' => $stage->planning_revision,
                ],
            ]), 'approved'];
        });
    }

    public function matchingApproval(VideoPlanningStage $stage): ?VideoReviewDecision
    {
        if ($stage->stage !== PlanningStageName::SCREENPLAY->value
            || $stage->status !== VideoPlanningStageStatus::SUCCEEDED->value
            || ! is_array($stage->output_json)) {
            return null;
        }

        $hash = ScreenplayContentHash::of($stage->output_json);
        $contract = (string) ($stage->output_json['schema_version'] ?? '');

        if ($contract !== $this->productionContract()) {
            return null;
        }

        $latest = VideoReviewDecision::query()
            ->where('project_id', $stage->project_id)
            ->where('entity_type', self::ENTITY_TYPE)
            ->where('entity_id', $stage->id)
            ->orderByDesc('revision')
            ->first();

        if ($latest === null || $latest->decision !== 'approved') {
            return null;
        }

        return hash_equals((string) ($latest->metadata_json['content_hash'] ?? ''), $hash)
            && ($latest->metadata_json['contract_version'] ?? null) === $contract
                ? $latest
                : null;
    }
}
