<?php

namespace App\Repositories\Interfaces;

use App\Models\VideoRender;
use App\Video\Render\Enums\RenderStatus;
use DateTimeImmutable;
use Illuminate\Support\Collection;

interface VideoRenderRepositoryInterface extends RepositoryInterface
{
    /**
     * @param  ?string  $renderId  null still runs the lookup (id is null)
     * @return ?VideoRender the render, of any owner
     */
    public function findRender(?string $renderId): ?VideoRender;

    /**
     * @param  ?string  $renderId
     * @return VideoRender the render, of any owner
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findRenderOrFail(?string $renderId): VideoRender;

    /**
     * @param  string  $renderId
     * @return ?string provider of the render as stored
     */
    public function findProviderOf(string $renderId): ?string;

    /**
     * @param  string  $renderId
     * @return ?RenderStatus execution_status of the render as the model casts it
     */
    public function findExecutionStatus(string $renderId): ?RenderStatus;

    /**
     * @param  string  $projectId
     * @param  string  $renderId
     * @return VideoRender the render when it is a video of a moto clip shot whose session belongs to the project
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findOwnedRender(string $projectId, string $renderId): VideoRender;

    /**
     * @param  string  $projectId
     * @param  string  $renderId
     * @return VideoRender the render when it is a video whose shot session or own session belongs to the project
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findOwnedLegacyVideoRenderOrFail(string $projectId, string $renderId): VideoRender;

    /**
     * @param  string  $projectId
     * @param  array<int, string>|Collection<int, string>  $renderIds
     * @return Collection<int, VideoRender> the videos of moto clip shots of the project with these ids, each with id and shot_code of its shot eager loaded
     */
    public function findMotoClipTasks(string $projectId, array|Collection $renderIds): Collection;

    /**
     * @param  ?string  $renderId  null still runs the lookup (id is null)
     * @param  string  $shotId
     * @return ?VideoRender the render when it belongs to the shot, whatever its purpose
     */
    public function findRenderOfShot(?string $renderId, string $shotId): ?VideoRender;

    /**
     * @param  ?string  $renderId
     * @param  string  $shotId
     * @return ?VideoRender the render when it is a production render of the shot
     */
    public function findProductionRenderOfShot(?string $renderId, string $shotId): ?VideoRender;

    /**
     * @param  ?string  $renderId
     * @param  string  $shotId
     * @return VideoRender the render when it is a production render of the shot
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findProductionRenderOfShotOrFail(?string $renderId, string $shotId): VideoRender;

    /**
     * @param  array<int, string>|Collection<int, string>  $shotIds  an empty list still runs the query
     * @return Collection<int, VideoRender> the production videos of these shots, by attempt number
     */
    public function findProductionVideoRendersOfShots(array|Collection $shotIds): Collection;

    /**
     * @param  array<int, string>|Collection<int, string>  $shotIds
     * @return Collection<int, VideoRender> the production videos of these shots, by creation then attempt number
     */
    public function findProductionVideoRendersOfShotsInCreationOrder(array|Collection $shotIds): Collection;

    /**
     * @param  string  $shotId
     * @param  string  $keyPrefix  the idempotency key the rows start with
     * @return Collection<int, VideoRender> id, execution_status and idempotency_key of the renders of the shot whose key starts with the prefix
     */
    public function findByShotIdempotencyPrefix(string $shotId, string $keyPrefix): Collection;

    /**
     * @param  DateTimeImmutable  $now
     * @param  int  $limit
     * @return Collection<int, mixed> the ids of the claimed, preparing, submitting or submitted renders whose lease ran out, in no given order
     */
    public function findExpiredLeaseIds(DateTimeImmutable $now, int $limit): Collection;

    /**
     * @param  DateTimeImmutable  $now
     * @param  int  $limit
     * @return Collection<int, mixed> the ids of the production videos of moto clip shots due to be followed, next poll first: running at the provider, stalled while polling or writing, or waiting with a free lease on a job that is known by its id or a receipt
     */
    public function findDueMotoClipRenderIds(DateTimeImmutable $now, int $limit): Collection;

    /**
     * @param  string  $designImageId
     * @return int the highest attempt number of the renders of the image, 0 when it has none
     */
    public function maxAttemptNoForDesignImage(string $designImageId): int;

    /**
     * @param  string  $shotId
     * @return int the highest attempt number of the renders of the shot, 0 when it has none
     */
    public function maxAttemptNoForShot(string $shotId): int;

    /**
     * @param  VideoRender  $render  its designImage, shot, shot.session and session relations are read lazily and kept on it
     * @return array{project_id: mixed, session_id: mixed} the project of the image, else of the shot session, else of the session; the session of the render, else of its shot
     */
    public function costOwnerIds(VideoRender $render): array;

    /**
     * @param  ?string  $sessionId  null matches a render without a session
     * @param  ?string  $designImageId  null matches a render without an image
     * @param  ?string  $shotId  null matches a render without a shot
     * @param  string  $idempotencyKey
     * @return ?VideoRender the render of that owner with that key, locked FOR UPDATE; call inside the caller's transaction
     */
    public function lockByIdempotencyKey(?string $sessionId, ?string $designImageId, ?string $shotId, string $idempotencyKey): ?VideoRender;

    /**
     * @param  DateTimeImmutable  $now
     * @return ?VideoRender the oldest claimable render an external worker may take (not of the local processor), locked FOR UPDATE; call inside the caller's transaction
     */
    public function lockNextClaimableForExternalWorker(DateTimeImmutable $now): ?VideoRender;

    /**
     * @param  string  $renderId
     * @param  DateTimeImmutable  $now
     * @return ?VideoRender the render when it is claimable, locked FOR UPDATE; call inside the caller's transaction
     */
    public function lockClaimableById(string $renderId, DateTimeImmutable $now): ?VideoRender;

    /**
     * @param  string  $renderId
     * @return ?VideoRender the render locked FOR UPDATE; call inside the caller's transaction
     */
    public function lockRender(string $renderId): ?VideoRender;

    /**
     * @param  string  $renderId
     * @return VideoRender the render locked FOR UPDATE; call inside the caller's transaction
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function lockRenderOrFail(string $renderId): VideoRender;

    /**
     * @param  string  $shotId
     * @param  list<RenderStatus>  $settledStatuses  the statuses left out
     * @return Collection<int, VideoRender> the production renders of the shot not in these statuses, locked FOR UPDATE; call inside the caller's transaction
     */
    public function lockOpenProductionRendersOfShot(string $shotId, array $settledStatuses): Collection;

    /**
     * @param  array<string, mixed>  $attributes  force filled, id included: the id is the one given, never generated by create()
     * @return VideoRender the new render
     */
    public function insertRender(array $attributes): VideoRender;

    /**
     * @param  VideoRender  $render  the render the caller holds, usually locked; it is written in place
     * @param  array<string, mixed>  $attributes  force filled, cast by the model
     * @return void
     */
    public function saveRender(VideoRender $render, array $attributes): void;

    /**
     * @param  VideoRender  $render
     * @return VideoRender the same instance read again from the table
     */
    public function refreshRender(VideoRender $render): VideoRender;

    /**
     * @param  string  $renderId
     * @param  string  $claimToken
     * @param  string  $workerId
     * @param  DateTimeImmutable  $now
     * @param  string  $providerJobId
     * @param  string  $submitResponseJson
     * @return int the rows written while the worker holds the lease of a submitting render under this claim
     */
    public function recordSubmittedJobIfLeaseHeld(string $renderId, string $claimToken, string $workerId, DateTimeImmutable $now, string $providerJobId, string $submitResponseJson): int;

    /**
     * @param  string  $renderId
     * @param  string  $claimToken
     * @param  string  $workerId
     * @param  DateTimeImmutable  $now
     * @param  array<string, mixed>  $artifact  the columns of the checked file, written as given
     * @return int the rows written while the worker holds the lease under this claim
     */
    public function recordArtifactIfLeaseHeld(string $renderId, string $claimToken, string $workerId, DateTimeImmutable $now, array $artifact): int;

    /**
     * @param  string  $renderId
     * @param  DateTimeImmutable  $at
     * @return int the rows whose next poll moved to $at
     */
    public function postponeNextPoll(string $renderId, DateTimeImmutable $at): int;

    /**
     * @param  ?string  $renderId  the render an operation recorded earlier names
     * @return VideoRender the render, of any owner, read by findOrFail
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException naming the id it looked for
     */
    public function findReplayedRenderOrFail(?string $renderId): VideoRender;
}
