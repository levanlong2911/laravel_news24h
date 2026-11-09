<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Enums\PlanningStageName;
use App\Models\VideoPlanningStage;
use App\Models\VideoRenderScene;
use RuntimeException;

final class ShotRenderPlanService
{
    /** @var string the key of state_json that ties a render row to the shot plan and the shot it stands for */
    public const SOURCE_KEY = 'shot_source';

    /**
     * @param  array{stage_id: string, plan: array<string, mixed>, token: array<string, mixed>}  $approved  what ShotSourceService::approvedSource() returned; the caller holds the row lock of the project in a transaction
     * @return VideoRenderScene the one render row of the shot in this shot plan, made the first time and returned unchanged after that
     *
     * @throws RuntimeException when the shot is not in the plan
     */
    public function ensure(string $projectId, array $approved, string $shotId): VideoRenderScene
    {
        $stageId = (string) $approved['stage_id'];
        $found = $this->find($projectId, $stageId, $shotId);

        if ($found !== null) {
            return $found;
        }

        $stage = $this->stage($projectId, $stageId) ?? throw new RuntimeException('Kế hoạch shot không thuộc dự án.');
        $shots = array_values((array) $approved['plan']['shots']);
        $position = array_search($shotId, array_column($shots, 'id'), true);

        if ($position === false) {
            throw new RuntimeException("Shot {$shotId} không có trong kế hoạch shot đã duyệt.");
        }

        $shot = $shots[$position];
        $inScene = array_column(array_filter($shots, static fn (array $row): bool => $row['scene'] === $shot['scene']), 'id');
        $continuity = collect((array) $shot['reference_needs'])->firstWhere('kind', 'continuity');

        return VideoRenderScene::create([
            'project_id' => $projectId,
            'revision' => (int) $stage->planning_revision,
            'scene_code' => $shotId,
            'scene_index' => $position + 1,
            'shot_index' => (int) array_search($shotId, $inScene, true) + 1,
            'purpose' => (string) $shot['purpose'],
            'duration_ms' => (int) $shot['render_duration_ms'],
            'delta_prompt' => (string) $shot['prompts']['image'],
            'prompt_version' => (string) $shot['prompts']['composer'],
            'transition_mode' => $continuity === null ? 'hard_cut_edit' : 'continuation_edit',
            'source_scene_code' => $continuity['target'] ?? null,
            'state_json' => [self::SOURCE_KEY => [
                'stage_id' => $stageId,
                'planning_revision' => (int) $stage->planning_revision,
                'content_hash' => (string) $approved['token']['content_hash'],
                'shot_id' => $shotId,
                'scene_id' => (string) $shot['scene'],
                'fingerprint' => (string) $shot['fingerprint'],
            ]],
        ]);
    }

    public function find(string $projectId, string $stageId, string $shotId): ?VideoRenderScene
    {
        return $this->forStage($projectId, $stageId)[$shotId] ?? null;
    }

    /** @return array<string, VideoRenderScene> the render row of every shot of this shot plan that has one, by shot id */
    public function forStage(string $projectId, string $stageId): array
    {
        $stage = $this->stage($projectId, $stageId);

        if ($stage === null) {
            return [];
        }

        return VideoRenderScene::query()
            ->where('project_id', $projectId)
            ->where('revision', (int) $stage->planning_revision)
            ->where('state_json->'.self::SOURCE_KEY.'->stage_id', $stageId)
            ->orderBy('scene_index')
            ->get()
            ->keyBy('scene_code')
            ->all();
    }

    private function stage(string $projectId, string $stageId): ?VideoPlanningStage
    {
        return VideoPlanningStage::query()->withoutSnapshot()->whereKey($stageId)->where('project_id', $projectId)->where('stage', PlanningStageName::SHOTS->value)->first();
    }
}
