<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use Illuminate\Support\Facades\DB;

final class ProductionSelectionService
{
    public function __construct(private readonly ScreenplayApprovalService $approvals) {}

    public function selectedScreenplay(VideoProject $project): ?VideoPlanningStage
    {
        $stageId = $project->selected_screenplay_stage_id;

        if (! is_string($stageId) || $stageId === '') {
            return null;
        }

        $stage = VideoPlanningStage::query()
            ->whereKey($stageId)
            ->where('project_id', $project->id)
            ->where('stage', PlanningStageName::SCREENPLAY->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->first();

        if ($stage === null || ! is_array($stage->output_json)) {
            return null;
        }

        $expected = (string) config('video.screenplay.scenes.assembled_version', 'screenplay_v4');

        if (($stage->output_json['schema_version'] ?? null) !== $expected
            || $this->approvals->matchingApproval($stage) === null) {
            return null;
        }

        return $stage;
    }

    /** @return array{0: ?VideoProject, 1: string} */
    public function selectScreenplay(string $projectId, string $stageId, int $expectedVersion): array
    {
        return DB::transaction(function () use ($projectId, $stageId, $expectedVersion): array {
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

            if ($stage === null || $this->approvals->matchingApproval($stage) === null) {
                return [null, 'screenplay_not_approved'];
            }

            if ((string) $project->selected_screenplay_stage_id === $stageId) {
                return [$project, 'already_selected'];
            }

            if ((int) $project->production_selection_version !== $expectedVersion) {
                return [null, 'production_selection_conflict'];
            }

            $nextVersion = $expectedVersion + 1;
            $metadata = is_array($project->metadata_json) ? $project->metadata_json : [];
            $history = is_array($metadata['production_selection_history'] ?? null)
                ? $metadata['production_selection_history']
                : [];
            $history[] = [
                'version' => $nextVersion,
                'screenplay_stage_id' => $stageId,
                'scene_plan_stage_id' => null,
                'selected_at' => now()->toIso8601String(),
            ];
            $metadata['production_selection_history'] = $history;

            $project->forceFill([
                'selected_screenplay_stage_id' => $stageId,
                'selected_scene_plan_stage_id' => null,
                'production_selection_version' => $nextVersion,
                'metadata_json' => $metadata,
            ])->save();

            return [$project->refresh(), 'selected'];
        });
    }
}
