<?php

namespace App\Repositories\Eloquent;

use App\Models\VideoRender;
use App\Models\VideoRenderScene;
use App\Models\VideoSession;
use App\Models\VideoShot;
use App\Repositories\Interfaces\VideoShotRepositoryInterface;
use App\Services\Video\MotoClipRenderService;
use App\Video\Render\Video\SceneShotFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class VideoShotRepository extends BaseRepository implements VideoShotRepositoryInterface
{
    public function getModel(): string
    {
        return VideoShot::class;
    }

    public function lockShotOfSession(string $sessionId, int $planRevision, string $shotCode, string $kind): ?VideoShot
    {
        return $this->model->newQuery()
            ->where('session_id', $sessionId)
            ->where('plan_revision', $planRevision)
            ->where('shot_code', $shotCode)
            ->where('kind', $kind)
            ->lockForUpdate()
            ->first();
    }

    public function lockShotOrFail(string $shotId): VideoShot
    {
        return $this->model->newQuery()->whereKey($shotId)->lockForUpdate()->firstOrFail();
    }

    public function lockShotsOfScenes(array|Collection $sceneIds): Collection
    {
        return $this->model->newQuery()
            ->whereIn('scene_id', $sceneIds)
            ->lockForUpdate()
            ->get();
    }

    public function lockMotoClipShots(string $projectId, ?string $shotPlanStageId, array|Collection $shotCodes): Collection
    {
        return $this->motoClipShots($projectId, $shotPlanStageId, $shotCodes)->lockForUpdate()->get();
    }

    public function findMotoClipShots(string $projectId, ?string $shotPlanStageId, array|Collection $shotCodes): Collection
    {
        return $this->motoClipShots($projectId, $shotPlanStageId, $shotCodes)->get();
    }

    public function findShotOrFail(?string $shotId): VideoShot
    {
        return $this->model->newQuery()->findOrFail($shotId);
    }

    public function findSceneShotOfProjectOrFail(string $projectId, string $shotId): VideoShot
    {
        return $this->model->newQuery()
            ->whereKey($shotId)
            ->whereIn('scene_id', VideoRenderScene::query()
                ->where('project_id', $projectId)
                ->select('id'))
            ->firstOrFail();
    }

    public function findSceneShotsForRevision(string $projectId, int $revision): Collection
    {
        return $this->model->newQuery()
            ->whereNotNull('scene_id')
            ->whereIn('scene_id', VideoRenderScene::query()
                ->where('project_id', $projectId)
                ->where('revision', $revision)
                ->select('id'))
            ->get();
    }

    public function findShotOfRender(VideoRender $render): ?VideoShot
    {
        return $this->model->newQuery()->whereKey($render->shot_id)->first();
    }

    public function loadShotOfRender(VideoRender $render): ?VideoShot
    {
        return $render->shot;
    }

    public function findShotCodeOfRender(VideoRender $render): ?string
    {
        return $this->model->newQuery()->whereKey($render->shot_id)->value('shot_code');
    }

    public function createShot(array $attributes): VideoShot
    {
        return $this->model->newQuery()->create($attributes);
    }

    public function saveShot(VideoShot $shot, array $attributes = []): void
    {
        $shot->forceFill($attributes)->save();
    }

    public function refreshShot(VideoShot $shot): VideoShot
    {
        return $shot->refresh();
    }

    /**
     * @param  string  $projectId
     * @param  ?string  $shotPlanStageId
     * @param  array<int, string>|Collection<int, string>  $shotCodes
     * @return Builder the moto clip shots of the project with these codes, of that shot plan when one is named
     */
    private function motoClipShots(string $projectId, ?string $shotPlanStageId, array|Collection $shotCodes): Builder
    {
        $query = $this->model->newQuery()
            ->whereIn('session_id', VideoSession::query()->where('project_id', $projectId)->select('id'))
            ->where('shot_type', MotoClipRenderService::SHOT_TYPE)
            ->where('kind', SceneShotFactory::KIND)
            ->whereIn('shot_code', $shotCodes);

        if ($shotPlanStageId !== null) {
            $query->where('metadata_json->moto_clip->shot_plan_stage_id', $shotPlanStageId);
        }

        return $query;
    }
}
