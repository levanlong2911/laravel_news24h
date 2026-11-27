<?php

namespace App\Repositories\Eloquent;

use App\Models\VideoDesignImage;
use App\Models\VideoRenderScene;
use App\Repositories\Interfaces\VideoRenderSceneRepositoryInterface;
use App\Services\Video\DesignImageStore;
use App\Services\Video\ShotRenderPlanService;
use App\Video\Scene\ScenePreservationPrompt;
use Illuminate\Support\Collection;

class VideoRenderSceneRepository extends BaseRepository implements VideoRenderSceneRepositoryInterface
{
    public function getModel(): string
    {
        return VideoRenderScene::class;
    }

    public function findScene(?string $sceneId): ?VideoRenderScene
    {
        return $this->model->newQuery()->whereKey($sceneId)->first();
    }

    public function findSceneForProject(string $projectId, string $sceneId): ?VideoRenderScene
    {
        return $this->model->newQuery()->whereKey($sceneId)->where('project_id', $projectId)->first();
    }

    public function findSceneForRevision(string $projectId, string $sceneId, int $revision): ?VideoRenderScene
    {
        return $this->model->newQuery()
            ->whereKey($sceneId)
            ->where('project_id', $projectId)
            ->where('revision', $revision)
            ->first();
    }

    public function findProjectIdOfScene(?string $sceneId): ?string
    {
        return $this->model->newQuery()->whereKey($sceneId)->value('project_id');
    }

    public function findContinuationSuccessor(VideoRenderScene $scene, array $columns = ['*']): ?VideoRenderScene
    {
        return $this->model->newQuery()
            ->where('project_id', $scene->project_id)
            ->where('revision', $scene->revision)
            ->where('source_scene_code', $scene->scene_code)
            ->where('transition_mode', ScenePreservationPrompt::CONTINUATION)
            ->where(fn ($query) => $scene->continuity_group === null
                ? $query->whereNull('continuity_group')
                : $query->where('continuity_group', $scene->continuity_group))
            ->orderBy('scene_index')
            ->first($columns);
    }

    public function findContinuationPredecessor(VideoRenderScene $scene): ?VideoRenderScene
    {
        return $this->model->newQuery()
            ->where('project_id', $scene->project_id)
            ->where('revision', $scene->revision)
            ->where('scene_code', $scene->source_scene_code)
            ->where(fn ($query) => $scene->continuity_group === null
                ? $query->whereNull('continuity_group')
                : $query->where('continuity_group', $scene->continuity_group))
            ->first();
    }

    public function findForShotPlan(string $projectId, int $revision, string $shotPlanStageId): array
    {
        return $this->model->newQuery()
            ->where('project_id', $projectId)
            ->where('revision', $revision)
            ->where('state_json->'.ShotRenderPlanService::SOURCE_KEY.'->stage_id', $shotPlanStageId)
            ->orderBy('scene_index')
            ->get()
            ->keyBy('scene_code')
            ->all();
    }

    public function findScenesForRevision(string $projectId, int $revision): Collection
    {
        return $this->model->newQuery()->where('project_id', $projectId)->where('revision', $revision)->orderBy('scene_index')->get();
    }

    public function findScenesForScreenplayRevision(string $projectId, int $revision, ?string $screenplayStageId): Collection
    {
        return $this->model->newQuery()
            ->where('project_id', $projectId)
            ->where('revision', $revision)
            ->where('screenplay_stage_id', $screenplayStageId)
            ->orderBy('scene_index')
            ->get();
    }

    public function findSceneAtIndex(string $projectId, int $revision, int $sceneIndex): ?VideoRenderScene
    {
        return $this->model->newQuery()
            ->where('project_id', $projectId)
            ->where('revision', $revision)
            ->where('scene_index', $sceneIndex)
            ->first(['id', 'title', 'state_json']);
    }

    public function findEarlierScenes(string $projectId, int $revision, int $sceneIndex): Collection
    {
        return $this->model->newQuery()
            ->where('project_id', $projectId)
            ->where('revision', $revision)
            ->where('scene_index', '<', $sceneIndex)
            ->orderBy('scene_index')
            ->get(['id', 'title', 'state_json']);
    }

    public function findSceneByCode(string $projectId, int $revision, string $sceneCode): ?VideoRenderScene
    {
        return $this->model->newQuery()
            ->where('project_id', $projectId)
            ->where('revision', $revision)
            ->where('scene_code', $sceneCode)
            ->first();
    }

    public function findHardCutKeyframeCarriers(string $projectId, int $revision): Collection
    {
        return $this->model->newQuery()
            ->where('project_id', $projectId)
            ->where('revision', $revision)
            ->where('transition_mode', ScenePreservationPrompt::HARD_CUT)
            ->whereIn('id', VideoDesignImage::query()
                ->where('project_id', $projectId)
                ->where('image_type', DesignImageStore::SCENE_KEYFRAME_TYPE)
                ->whereNotNull('render_scene_id')
                ->select('render_scene_id'))
            ->orderBy('scene_index')
            ->get();
    }

    public function findSceneRevision(?string $sceneId): ?VideoRenderScene
    {
        return $this->model->newQuery()->whereKey($sceneId)->first(['id', 'revision']);
    }

    public function findSceneTitle(string $sceneId): ?VideoRenderScene
    {
        return $this->model->newQuery()->whereKey($sceneId)->first(['title']);
    }

    public function pluckSceneCodes(string $projectId, int $revision, array|Collection $sceneIds): array
    {
        return $this->model->newQuery()
            ->where('project_id', $projectId)
            ->where('revision', $revision)
            ->whereIn('id', $sceneIds)
            ->pluck('scene_code', 'id')
            ->all();
    }
}
