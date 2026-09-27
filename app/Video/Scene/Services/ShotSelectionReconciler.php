<?php

declare(strict_types=1);

namespace App\Video\Scene\Services;

use App\Enums\DesignImageStatus;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoDesignImage;
use App\Models\VideoProject;
use App\Models\VideoRender;
use App\Models\VideoRenderScene;
use App\Models\VideoShot;
use App\Services\Video\DesignImageStore;
use App\Services\Video\ScreenplayApprovalService;
use App\Video\Render\Enums\RenderStatus;
use App\Video\Render\Video\SceneClipDispatchService;
use App\Video\Scene\ScenePreservationPrompt;
use App\Video\Screenplay\ScreenplayContentHash;

final class ShotSelectionReconciler
{
    private const RULE_VERSION = 'shot-selection-v1';

    public function __construct(private readonly ScreenplayApprovalService $approvals) {}

    /**
     * @return array{status: 'valid'|'stale'|'needs_confirmation', reasons: list<string>, fingerprint: string}
     */
    public function verdict(VideoShot $shot, ?VideoRender $render = null): array
    {
        $render ??= $shot->video_render_id === null
            ? null
            : VideoRender::query()->whereKey($shot->video_render_id)->first();
        $reasons = [];
        $needsConfirmation = false;
        $evidence = [
            'rule_version' => self::RULE_VERSION,
            'shot_id' => (string) $shot->id,
            'render' => $render === null ? null : [
                'id' => (string) $render->id,
                'shot_id' => (string) $render->shot_id,
                'purpose' => (string) $render->execution_purpose,
                'status' => $render->execution_status?->value,
                'artifact_path' => (string) $render->artifact_path,
                'artifact_hash' => (string) $render->primary_artifact_hash,
            ],
        ];

        if ($render === null) {
            return $this->result('stale', ['selected_render_missing'], $evidence);
        }

        if ((string) $render->shot_id !== (string) $shot->id
            || (string) $render->execution_purpose !== SceneClipDispatchService::PURPOSE_PRODUCTION
            || $render->execution_status !== RenderStatus::SUCCEEDED) {
            $reasons[] = 'selected_render_not_usable';
        }

        if (trim((string) $render->artifact_path) === ''
            || preg_match('/^[a-f0-9]{64}$/', (string) $render->primary_artifact_hash) !== 1) {
            $reasons[] = 'selected_artifact_unverifiable';
        }

        $request = json_decode((string) $render->render_request_json, true);

        if (! is_array($request)) {
            $evidence['request'] = null;

            return $reasons === []
                ? $this->result('needs_confirmation', ['render_snapshot_missing'], $evidence)
                : $this->result('stale', array_values(array_unique($reasons)), $evidence);
        }

        $evidence['request'] = [
            'compiled_prompt' => $request['compiled_prompt']['prompt'] ?? null,
            'motion_spec_hash' => $request['motion_spec_hash'] ?? null,
            'source_artifact_id' => $request['source_artifact_id'] ?? null,
            'source_scene_id' => $request['source_artifact']['scene_id'] ?? null,
            'end_artifact_id' => $request['end_frame']['artifact_id'] ?? null,
            'end_scene_id' => $request['end_frame']['scene_id'] ?? null,
        ];

        $scene = $shot->scene_id === null
            ? null
            : VideoRenderScene::query()->whereKey($shot->scene_id)->first();

        [$selectedProduction, $productionEvidence] = $this->selectedProduction($scene);
        $evidence['production'] = $productionEvidence;

        if (! $selectedProduction) {
            $reasons[] = 'scene_plan_not_selected';
        }

        $currentMotionHash = self::motionSpecHash($shot);

        if (($request['compiled_prompt']['prompt'] ?? null) !== (string) $shot->compiled_prompt) {
            $reasons[] = 'compiled_prompt_changed';
        }

        if (! hash_equals(
            (string) ($request['motion_spec_hash'] ?? ''),
            $currentMotionHash,
        )) {
            $reasons[] = 'motion_spec_changed';
        }

        $sourceId = $this->approvedArtifactId((string) $shot->scene_id);
        $evidence['current'] = [
            'compiled_prompt' => (string) $shot->compiled_prompt,
            'motion_spec_hash' => $currentMotionHash,
            'source_artifact_id' => $sourceId,
            'source_scene_id' => (string) $shot->scene_id,
        ];

        if ($sourceId === null || (string) ($request['source_artifact_id'] ?? '') !== $sourceId
            || (string) ($request['source_artifact']['scene_id'] ?? '') !== (string) $shot->scene_id) {
            $reasons[] = 'source_artifact_changed';
        }

        $snapshotEnd = is_array($request['end_frame'] ?? null)
            ? (string) ($request['end_frame']['artifact_id'] ?? '')
            : null;
        $snapshotEndScene = is_array($request['end_frame'] ?? null)
            ? (string) ($request['end_frame']['scene_id'] ?? '')
            : null;
        $currentEnd = $scene === null
            ? ['required' => false, 'artifact_id' => null, 'scene_id' => null]
            : $this->requiredEndArtifact($scene);
        $evidence['current']['end_frame'] = $currentEnd;

        if ($currentEnd['required'] && $currentEnd['artifact_id'] === null) {
            $reasons[] = 'required_end_frame_unapproved';
        } elseif ($currentEnd['required'] && $snapshotEnd === null) {
            $reasons[] = 'end_frame_now_required';
        } elseif ($currentEnd['required'] && $snapshotEnd !== $currentEnd['artifact_id']) {
            $reasons[] = 'end_frame_changed';
        } elseif ($currentEnd['required'] && $snapshotEndScene !== $currentEnd['scene_id']) {
            $reasons[] = 'end_frame_scene_changed';
        } elseif (! $currentEnd['required'] && $snapshotEnd !== null) {
            $needsConfirmation = true;
        }

        if ($reasons !== []) {
            return $this->result('stale', array_values(array_unique($reasons)), $evidence);
        }

        return $this->result(
            $needsConfirmation ? 'needs_confirmation' : 'valid',
            $needsConfirmation ? ['end_frame_no_longer_required'] : [],
            $evidence,
        );
    }

    public static function motionSpecHash(VideoShot $shot): string
    {
        $spec = $shot->spec_json ?? [];

        return hash('sha256', (string) json_encode([
            'motion' => $spec['motion'] ?? [],
            'camera' => $spec['camera'] ?? [],
            'duration_seconds' => $spec['duration_seconds'] ?? null,
            'from_state_id' => $shot->from_state_id,
            'to_state_id' => $shot->to_state_id,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @return array{0: bool, 1: array<string, mixed>} */
    private function selectedProduction(?VideoRenderScene $scene): array
    {
        if ($scene === null) {
            return [false, ['scene' => null]];
        }

        $project = VideoProject::query()->whereKey($scene->project_id)->first();
        $plan = $project?->selectedScenePlanStage()->first();
        $screenplay = $project?->selectedScreenplayStage()->first();
        $planOutput = is_array($plan?->output_json) ? $plan->output_json : [];
        $screenplayOutput = is_array($screenplay?->output_json) ? $screenplay->output_json : [];
        $approval = $screenplay === null ? null : $this->approvals->matchingApproval($screenplay);
        $screenplayHash = $screenplayOutput !== []
            ? ScreenplayContentHash::of($screenplayOutput)
            : null;
        $valid = $project !== null
            && (string) $project->selected_screenplay_stage_id === (string) $scene->screenplay_stage_id
            && $plan !== null
            && $plan->status === VideoPlanningStageStatus::SUCCEEDED->value
            && ($planOutput['review']['status'] ?? null) === 'passed'
            && (int) ($planOutput['revision'] ?? 0) === (int) $scene->revision
            && $screenplay !== null
            && $approval !== null
            && $screenplayHash !== null
            && hash_equals(
                (string) $scene->screenplay_hash,
                $screenplayHash,
            );

        return [$valid, [
            'project_id' => (string) $scene->project_id,
            'selection_version' => (int) ($project?->production_selection_version ?? 0),
            'selected_screenplay_stage_id' => $project?->selected_screenplay_stage_id,
            'selected_scene_plan_stage_id' => $project?->selected_scene_plan_stage_id,
            'scene' => [
                'id' => (string) $scene->id,
                'revision' => (int) $scene->revision,
                'screenplay_stage_id' => $scene->screenplay_stage_id,
                'screenplay_hash' => $scene->screenplay_hash,
            ],
            'plan' => $plan === null ? null : [
                'id' => (string) $plan->id,
                'status' => (string) $plan->status,
                'revision' => (int) ($planOutput['revision'] ?? 0),
                'review_status' => $planOutput['review']['status'] ?? null,
            ],
            'screenplay' => $screenplay === null ? null : [
                'id' => (string) $screenplay->id,
                'content_hash' => $screenplayHash,
                'contract_version' => $screenplayOutput['schema_version'] ?? null,
                'approval_matches' => $approval !== null,
            ],
        ]];
    }

    private function approvedArtifactId(string $sceneId): ?string
    {
        if ($sceneId === '') {
            return null;
        }

        $image = VideoDesignImage::query()
            ->where('render_scene_id', $sceneId)
            ->where('image_type', DesignImageStore::SCENE_KEYFRAME_TYPE)
            ->where('status', DesignImageStatus::APPROVED->value)
            ->first();

        return $image?->selected_artifact_id === null ? null : (string) $image->selected_artifact_id;
    }

    /** @return array{required: bool, artifact_id: ?string, scene_id: ?string} */
    private function requiredEndArtifact(VideoRenderScene $scene): array
    {
        $next = VideoRenderScene::query()
            ->where('project_id', $scene->project_id)
            ->where('revision', $scene->revision)
            ->where('source_scene_code', $scene->scene_code)
            ->where('transition_mode', ScenePreservationPrompt::CONTINUATION)
            ->where(fn ($query) => $scene->continuity_group === null
                ? $query->whereNull('continuity_group')
                : $query->where('continuity_group', $scene->continuity_group))
            ->orderBy('scene_index')
            ->first();

        return [
            'required' => $next !== null,
            'artifact_id' => $next === null ? null : $this->approvedArtifactId((string) $next->id),
            'scene_id' => $next === null ? null : (string) $next->id,
        ];
    }

    /**
     * @param  'valid'|'stale'|'needs_confirmation'  $status
     * @param  list<string>  $reasons
     * @return array{status: 'valid'|'stale'|'needs_confirmation', reasons: list<string>, fingerprint: string}
     */
    private function result(string $status, array $reasons, array $evidence): array
    {
        return [
            'status' => $status,
            'reasons' => $reasons,
            'fingerprint' => hash('sha256', json_encode(
                $evidence,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            )),
        ];
    }
}
