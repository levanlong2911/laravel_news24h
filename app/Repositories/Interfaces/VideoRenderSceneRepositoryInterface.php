<?php

namespace App\Repositories\Interfaces;

use App\Models\VideoRenderScene;
use Illuminate\Support\Collection;

interface VideoRenderSceneRepositoryInterface extends RepositoryInterface
{
    /**
     * @param  ?string  $sceneId  null still runs the lookup (id is null)
     * @return ?VideoRenderScene the scene, of any project
     */
    public function findScene(?string $sceneId): ?VideoRenderScene;

    /**
     * @param  string  $projectId
     * @param  string  $sceneId
     * @return ?VideoRenderScene the scene of the project
     */
    public function findSceneForProject(string $projectId, string $sceneId): ?VideoRenderScene;

    /**
     * @param  string  $projectId
     * @param  string  $sceneId
     * @param  int  $revision
     * @return ?VideoRenderScene the scene of the project in that revision
     */
    public function findSceneForRevision(string $projectId, string $sceneId, int $revision): ?VideoRenderScene;

    /**
     * @param  ?string  $sceneId  null still runs the lookup (id is null)
     * @return ?string project_id of the scene as stored
     */
    public function findProjectIdOfScene(?string $sceneId): ?string;

    /**
     * @param  VideoRenderScene  $scene
     * @param  list<string>  $columns
     * @return ?VideoRenderScene the first scene of the same project, revision and continuity group (null group matches null) that continues this one, by scene index
     */
    public function findContinuationSuccessor(VideoRenderScene $scene, array $columns = ['*']): ?VideoRenderScene;

    /**
     * @param  VideoRenderScene  $scene
     * @return ?VideoRenderScene a scene of the same project, revision and continuity group (null group matches null) whose code is the source scene code of this one, in no given order
     */
    public function findContinuationPredecessor(VideoRenderScene $scene): ?VideoRenderScene;

    /**
     * @param  string  $projectId
     * @param  int  $revision
     * @param  string  $shotPlanStageId  state_json.shot_source.stage_id
     * @return array<string, VideoRenderScene> the scenes of the project in that revision made for the shot plan, by scene index, keyed by scene code
     */
    public function findForShotPlan(string $projectId, int $revision, string $shotPlanStageId): array;

    /**
     * @param  string  $projectId
     * @param  int  $revision
     * @return Collection<int, VideoRenderScene> the scenes of the project in that revision, by scene index
     */
    public function findScenesForRevision(string $projectId, int $revision): Collection;

    /**
     * @param  string  $projectId
     * @param  int  $revision
     * @param  ?string  $screenplayStageId  null matches scenes without a screenplay stage
     * @return Collection<int, VideoRenderScene> the scenes of the project in that revision made from that screenplay, by scene index
     */
    public function findScenesForScreenplayRevision(string $projectId, int $revision, ?string $screenplayStageId): Collection;

    /**
     * @param  string  $projectId
     * @param  int  $revision
     * @param  int  $sceneIndex
     * @return ?VideoRenderScene id, title and state_json of the scene of the project at that index of the revision
     */
    public function findSceneAtIndex(string $projectId, int $revision, int $sceneIndex): ?VideoRenderScene;

    /**
     * @param  string  $projectId
     * @param  int  $revision
     * @param  int  $sceneIndex
     * @return Collection<int, VideoRenderScene> id, title and state_json of the scenes of the project before that index of the revision, by scene index
     */
    public function findEarlierScenes(string $projectId, int $revision, int $sceneIndex): Collection;

    /**
     * @param  string  $projectId
     * @param  int  $revision
     * @param  string  $sceneCode
     * @return ?VideoRenderScene the scene of the project with that code in the revision
     */
    public function findSceneByCode(string $projectId, int $revision, string $sceneCode): ?VideoRenderScene;

    /**
     * @param  string  $projectId
     * @param  int  $revision
     * @return Collection<int, VideoRenderScene> the hard cut scenes of the project in that revision that carry a scene keyframe of the project, by scene index
     */
    public function findHardCutKeyframeCarriers(string $projectId, int $revision): Collection;

    /**
     * @param  ?string  $sceneId
     * @return ?VideoRenderScene id and revision of the scene, of any project
     */
    public function findSceneRevision(?string $sceneId): ?VideoRenderScene;

    /**
     * @param  string  $sceneId
     * @return ?VideoRenderScene the title of the scene, of any project
     */
    public function findSceneTitle(string $sceneId): ?VideoRenderScene;

    /**
     * @param  string  $projectId
     * @param  int  $revision
     * @param  array<int, string>|Collection<int, string>  $sceneIds
     * @return array<string, mixed> scene_code by id of the scenes of the project in that revision with these ids
     */
    public function pluckSceneCodes(string $projectId, int $revision, array|Collection $sceneIds): array;
}
