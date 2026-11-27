<?php

namespace App\Repositories\Interfaces;

use App\Models\VideoRender;
use App\Models\VideoShot;
use Illuminate\Support\Collection;

interface VideoShotRepositoryInterface extends RepositoryInterface
{
    /**
     * @param  string  $sessionId
     * @param  int  $planRevision
     * @param  string  $shotCode
     * @param  string  $kind
     * @return ?VideoShot the shot of the session found by its unique key, locked FOR UPDATE; call inside the caller's transaction
     */
    public function lockShotOfSession(string $sessionId, int $planRevision, string $shotCode, string $kind): ?VideoShot;

    /**
     * @param  string  $shotId
     * @return VideoShot the shot locked FOR UPDATE; call inside the caller's transaction
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function lockShotOrFail(string $shotId): VideoShot;

    /**
     * @param  array<int, string>|Collection<int, string>  $sceneIds
     * @return Collection<int, VideoShot> the shots on these scenes locked FOR UPDATE; call inside the caller's transaction
     */
    public function lockShotsOfScenes(array|Collection $sceneIds): Collection;

    /**
     * @param  string  $projectId
     * @param  ?string  $shotPlanStageId  null reads the shots of every shot plan
     * @param  array<int, string>|Collection<int, string>  $shotCodes
     * @return Collection<int, VideoShot> the moto clip shots of the project with these codes, of that shot plan, locked FOR UPDATE; call inside the caller's transaction
     */
    public function lockMotoClipShots(string $projectId, ?string $shotPlanStageId, array|Collection $shotCodes): Collection;

    /**
     * @param  string  $projectId
     * @param  ?string  $shotPlanStageId  null reads the shots of every shot plan
     * @param  array<int, string>|Collection<int, string>  $shotCodes
     * @return Collection<int, VideoShot> the moto clip shots of the project with these codes, of that shot plan
     */
    public function findMotoClipShots(string $projectId, ?string $shotPlanStageId, array|Collection $shotCodes): Collection;

    /**
     * @param  ?string  $shotId
     * @return VideoShot the shot, of any project
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findShotOrFail(?string $shotId): VideoShot;

    /**
     * @param  string  $projectId
     * @param  string  $shotId
     * @return VideoShot the shot when it is on a scene of the project
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findSceneShotOfProjectOrFail(string $projectId, string $shotId): VideoShot;

    /**
     * @param  string  $projectId
     * @param  int  $revision
     * @return Collection<int, VideoShot> the shots on the scenes of the project in that revision, in no given order
     */
    public function findSceneShotsForRevision(string $projectId, int $revision): Collection;

    /**
     * @param  VideoRender  $render
     * @return ?VideoShot the shot of the render, always read from the table; the relation of the render is left as it is
     */
    public function findShotOfRender(VideoRender $render): ?VideoShot;

    /**
     * @param  VideoRender  $render  its shot relation is loaded and kept on it
     * @return ?VideoShot the shot relation of the render: read once when not loaded yet, never read when shot_id is null
     */
    public function loadShotOfRender(VideoRender $render): ?VideoShot;

    /**
     * @param  VideoRender  $render
     * @return ?string shot_code of the shot of the render
     */
    public function findShotCodeOfRender(VideoRender $render): ?string;

    /**
     * @param  array<string, mixed>  $attributes  fillable attributes
     * @return VideoShot the new shot with its generated id
     */
    public function createShot(array $attributes): VideoShot;

    /**
     * @param  VideoShot  $shot  the shot the caller holds; it is written in place
     * @param  array<string, mixed>  $attributes  force filled before the save; empty saves what is already dirty
     * @return void
     */
    public function saveShot(VideoShot $shot, array $attributes = []): void;

    /**
     * @param  VideoShot  $shot
     * @return VideoShot the same instance read again from the table
     */
    public function refreshShot(VideoShot $shot): VideoShot;
}
