<?php

namespace App\Repositories\Eloquent;

use App\Models\VideoRender;
use App\Repositories\Interfaces\VideoRenderRepositoryInterface;
use App\Services\Video\MotoClipRenderService;
use App\Video\Moto\MotoClipPlanBuilder;
use App\Video\Render\Enums\RenderStatus;
use App\Video\Render\Video\SceneClipDispatchService;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VideoRenderRepository extends BaseRepository implements VideoRenderRepositoryInterface
{
    public function getModel(): string
    {
        return VideoRender::class;
    }

    public function findRender(?string $renderId): ?VideoRender
    {
        return $this->model->newQuery()->whereKey($renderId)->first();
    }

    public function findRenderOrFail(?string $renderId): VideoRender
    {
        return $this->model->newQuery()->whereKey($renderId)->firstOrFail();
    }

    public function findProviderOf(string $renderId): ?string
    {
        return $this->model->newQuery()->whereKey($renderId)->value('provider');
    }

    public function findExecutionStatus(string $renderId): ?RenderStatus
    {
        return $this->model->newQuery()->whereKey($renderId)->value('execution_status');
    }

    public function findOwnedRender(string $projectId, string $renderId): VideoRender
    {
        return $this->model->newQuery()
            ->whereKey($renderId)
            ->where('render_kind', 'video')
            ->whereHas('shot', static fn ($shot) => $shot->where('shot_type', MotoClipRenderService::SHOT_TYPE)->whereHas('session', static fn ($session) => $session->where('project_id', $projectId)))
            ->firstOrFail();
    }

    public function findOwnedLegacyVideoRenderOrFail(string $projectId, string $renderId): VideoRender
    {
        return $this->model->newQuery()
            ->whereKey($renderId)
            ->where('render_kind', 'video')
            ->where(fn ($query) => $query
                ->whereHas('shot.session', fn ($scope) => $scope->where('project_id', $projectId))
                ->orWhereHas('session', fn ($scope) => $scope->where('project_id', $projectId)))
            ->firstOrFail();
    }

    public function findMotoClipTasks(string $projectId, array|Collection $renderIds): Collection
    {
        return $this->model->newQuery()->whereIn('id', $renderIds)->where('render_kind', 'video')
            ->whereHas('shot', static fn ($shot) => $shot->where('shot_type', MotoClipRenderService::SHOT_TYPE)->whereHas('session', static fn ($session) => $session->where('project_id', $projectId)))
            ->with('shot:id,shot_code')->get();
    }

    public function findRenderOfShot(?string $renderId, string $shotId): ?VideoRender
    {
        return $this->model->newQuery()->whereKey($renderId)->where('shot_id', $shotId)->first();
    }

    public function findProductionRenderOfShot(?string $renderId, string $shotId): ?VideoRender
    {
        return $this->model->newQuery()
            ->whereKey($renderId)
            ->where('shot_id', $shotId)
            ->where('execution_purpose', SceneClipDispatchService::PURPOSE_PRODUCTION)
            ->first();
    }

    public function findProductionRenderOfShotOrFail(?string $renderId, string $shotId): VideoRender
    {
        return $this->model->newQuery()
            ->whereKey($renderId)
            ->where('shot_id', $shotId)
            ->where('execution_purpose', SceneClipDispatchService::PURPOSE_PRODUCTION)
            ->firstOrFail();
    }

    public function findProductionVideoRendersOfShots(array|Collection $shotIds): Collection
    {
        return $this->model->newQuery()
            ->whereIn('shot_id', $shotIds)
            ->where('render_kind', 'video')
            ->where('execution_purpose', SceneClipDispatchService::PURPOSE_PRODUCTION)
            ->orderBy('attempt_no')
            ->get();
    }

    public function findProductionVideoRendersOfShotsInCreationOrder(array|Collection $shotIds): Collection
    {
        return $this->model->newQuery()->whereIn('shot_id', $shotIds)->where('render_kind', 'video')->where('execution_purpose', SceneClipDispatchService::PURPOSE_PRODUCTION)
            ->orderBy('created_at')->orderBy('attempt_no')->get();
    }

    public function findByShotIdempotencyPrefix(string $shotId, string $keyPrefix): Collection
    {
        return $this->model->newQuery()
            ->where('shot_id', $shotId)
            ->where('idempotency_key', 'like', $keyPrefix.'%')
            ->get(['id', 'execution_status', 'idempotency_key']);
    }

    public function findExpiredLeaseIds(DateTimeImmutable $now, int $limit): Collection
    {
        return $this->model->newQuery()
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<', $now)
            ->whereIn('execution_status', [
                RenderStatus::CLAIMED->value,
                RenderStatus::PREPARING->value,
                RenderStatus::SUBMITTING->value,
                RenderStatus::SUBMITTED->value,
            ])
            ->limit($limit)
            ->pluck('id');
    }

    public function findDueMotoClipRenderIds(DateTimeImmutable $now, int $limit): Collection
    {
        $free = static fn ($lease) => $lease->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<', $now);

        return $this->model->newQuery()
            ->where('render_kind', 'video')
            ->where('execution_purpose', SceneClipDispatchService::PURPOSE_PRODUCTION)
            ->whereHas('shot', static fn ($shot) => $shot->where('shot_type', MotoClipRenderService::SHOT_TYPE))
            ->where(static fn ($due) => $due->whereNull('provider_next_poll_at')->orWhere('provider_next_poll_at', '<=', $now))
            ->where(static fn ($movable) => $movable
                ->where('execution_status', RenderStatus::PROVIDER_RUNNING->value)
                ->orWhere(static fn ($stalled) => $stalled
                    ->whereIn('execution_status', [RenderStatus::POLLING->value, RenderStatus::ARTIFACT_WRITING->value])
                    ->where('lease_expires_at', '<', $now))
                ->orWhere(static fn ($known) => $known
                    ->whereIn('execution_status', [RenderStatus::SUBMITTING->value, RenderStatus::SUBMITTED->value, RenderStatus::PROVIDER_UNKNOWN->value])
                    ->where($free)
                    ->where(static fn ($job) => $job
                        ->where(static fn ($column) => $column->whereNotNull('provider_job_id')->where('provider_job_id', '<>', ''))
                        ->orWhereExists(static fn ($receipt) => $receipt->select(DB::raw(1))->from('video_provider_submission_receipts')
                            ->whereColumn('video_provider_submission_receipts.render_id', 'video_renders.id')))))
            ->orderBy('provider_next_poll_at')
            ->limit($limit)
            ->pluck('id');
    }

    public function maxAttemptNoForDesignImage(string $designImageId): int
    {
        return (int) $this->model->newQuery()->where('design_image_id', $designImageId)->max('attempt_no');
    }

    public function maxAttemptNoForShot(string $shotId): int
    {
        return (int) $this->model->newQuery()->where('shot_id', $shotId)->max('attempt_no');
    }

    public function costOwnerIds(VideoRender $render): array
    {
        return [
            'project_id' => $render->designImage?->project_id
                ?? $render->shot?->session?->project_id
                ?? $render->session?->project_id,
            'session_id' => $render->video_session_id ?? $render->shot?->session_id,
        ];
    }

    public function lockByIdempotencyKey(?string $sessionId, ?string $designImageId, ?string $shotId, string $idempotencyKey): ?VideoRender
    {
        return $this->model->newQuery()
            ->where(fn ($query) => $sessionId === null
                ? $query->whereNull('video_session_id')
                : $query->where('video_session_id', $sessionId))
            ->where(fn ($query) => $designImageId === null
                ? $query->whereNull('design_image_id')
                : $query->where('design_image_id', $designImageId))
            ->where(fn ($query) => $shotId === null
                ? $query->whereNull('shot_id')
                : $query->where('shot_id', $shotId))
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();
    }

    public function lockNextClaimableForExternalWorker(DateTimeImmutable $now): ?VideoRender
    {
        return $this->claimable($now)
            ->where(static fn (Builder $query) => $query->whereNull('provider')->orWhere('provider', '<>', MotoClipPlanBuilder::LOCAL))
            ->orderBy('created_at')
            ->lockForUpdate()
            ->first();
    }

    public function lockClaimableById(string $renderId, DateTimeImmutable $now): ?VideoRender
    {
        return $this->claimable($now)
            ->whereKey($renderId)
            ->lockForUpdate()
            ->first();
    }

    public function lockRender(string $renderId): ?VideoRender
    {
        return $this->model->newQuery()->whereKey($renderId)->lockForUpdate()->first();
    }

    public function lockRenderOrFail(string $renderId): VideoRender
    {
        return $this->model->newQuery()->whereKey($renderId)->lockForUpdate()->firstOrFail();
    }

    public function lockOpenProductionRendersOfShot(string $shotId, array $settledStatuses): Collection
    {
        return $this->model->newQuery()
            ->where('shot_id', $shotId)
            ->where('execution_purpose', SceneClipDispatchService::PURPOSE_PRODUCTION)
            ->whereNotIn('execution_status', array_map(static fn (RenderStatus $status): string => $status->value, $settledStatuses))
            ->lockForUpdate()
            ->get();
    }

    public function insertRender(array $attributes): VideoRender
    {
        $render = $this->model->newInstance();

        $render->forceFill($attributes)->save();

        return $render;
    }

    public function saveRender(VideoRender $render, array $attributes): void
    {
        $render->forceFill($attributes)->save();
    }

    public function refreshRender(VideoRender $render): VideoRender
    {
        return $render->refresh();
    }

    public function recordSubmittedJobIfLeaseHeld(string $renderId, string $claimToken, string $workerId, DateTimeImmutable $now, string $providerJobId, string $submitResponseJson): int
    {
        return $this->model->newQuery()
            ->whereKey($renderId)
            ->where('claim_token', $claimToken)
            ->where('claimed_by', $workerId)
            ->where('lease_expires_at', '>', $now)
            ->where('execution_status', RenderStatus::SUBMITTING->value)
            ->update([
                'provider_job_id' => $providerJobId,
                'provider_submit_response_json' => $submitResponseJson,
            ]);
    }

    public function recordArtifactIfLeaseHeld(string $renderId, string $claimToken, string $workerId, DateTimeImmutable $now, array $artifact): int
    {
        return $this->leaseHeld($renderId, $claimToken, $workerId, $now)->update($artifact);
    }

    public function postponeNextPoll(string $renderId, DateTimeImmutable $at): int
    {
        return $this->model->newQuery()->whereKey($renderId)->update(['provider_next_poll_at' => $at]);
    }

    /**
     * @param  DateTimeImmutable  $now
     * @return Builder the renders a worker may claim at $now: queued, or waiting for a retry that is due, with a frozen request and attempts left
     */
    private function claimable(DateTimeImmutable $now): Builder
    {
        return $this->model->newQuery()
            ->where(function ($query) use ($now): void {
                $query
                    ->where('execution_status', RenderStatus::QUEUED->value)
                    ->orWhere(function ($retry) use ($now): void {
                        $retry
                            ->where('execution_status', RenderStatus::RETRY_WAIT->value)
                            ->where('next_retry_at', '<=', $now);
                    });
            })
            ->whereNotNull('request_hash')
            ->whereNotNull('render_request_json')
            ->where('attempt_count', '<', DB::raw('max_attempts'));
    }

    /**
     * @param  string  $renderId
     * @param  string  $claimToken
     * @param  string  $workerId
     * @param  DateTimeImmutable  $now
     * @return Builder the render while the worker holds its lease under this claim at $now
     */
    private function leaseHeld(string $renderId, string $claimToken, string $workerId, DateTimeImmutable $now): Builder
    {
        return $this->model->newQuery()
            ->whereKey($renderId)
            ->where('claim_token', $claimToken)
            ->where('claimed_by', $workerId)
            ->where('lease_expires_at', '>', $now);
    }

    public function findReplayedRenderOrFail(?string $renderId): VideoRender
    {
        return $this->model->newQuery()->findOrFail($renderId);
    }
}
