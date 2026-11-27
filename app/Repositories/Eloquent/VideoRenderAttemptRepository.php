<?php

namespace App\Repositories\Eloquent;

use App\Models\VideoRenderAttempt;
use App\Repositories\Interfaces\VideoRenderAttemptRepositoryInterface;
use App\Video\Render\Enums\RenderAttemptStatus;
use DateTimeImmutable;

class VideoRenderAttemptRepository extends BaseRepository implements VideoRenderAttemptRepositoryInterface
{
    public function getModel(): string
    {
        return VideoRenderAttempt::class;
    }

    public function createAttempt(array $attributes): VideoRenderAttempt
    {
        return $this->model->newQuery()->create($attributes);
    }

    public function lockCurrentAttempt(string $renderId, int $attemptNo): ?VideoRenderAttempt
    {
        return $this->model->newQuery()
            ->where('render_id', $renderId)
            ->where('attempt_no', $attemptNo)
            ->lockForUpdate()
            ->first();
    }

    public function lockCurrentAttemptOrFail(string $renderId, int $attemptNo): VideoRenderAttempt
    {
        return $this->model->newQuery()
            ->where('render_id', $renderId)
            ->where('attempt_no', $attemptNo)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function findCurrentAttempt(string $renderId, int $attemptNo): ?VideoRenderAttempt
    {
        return $this->model->newQuery()
            ->where('render_id', $renderId)
            ->where('attempt_no', $attemptNo)
            ->first();
    }

    public function findAttemptForClaim(string $renderId, int $attemptNo, string $claimToken, int $claimGeneration): ?VideoRenderAttempt
    {
        return $this->model->newQuery()
            ->where('render_id', $renderId)
            ->where('attempt_no', $attemptNo)
            ->where('claim_token', $claimToken)
            ->where('claim_generation', $claimGeneration)
            ->first();
    }

    public function hasNewerAttempt(string $renderId, int $attemptNo): bool
    {
        return $this->model->newQuery()
            ->where('render_id', $renderId)
            ->where('attempt_no', '>', $attemptNo)
            ->exists();
    }

    public function saveAttempt(VideoRenderAttempt $attempt, array $attributes): void
    {
        $attempt->forceFill($attributes)->save();
    }

    public function markCurrentAttemptSubmitted(string $renderId, int $attemptNo, string $providerJobId, ?string $providerRequestId, array $submitResponse, DateTimeImmutable $submittedAt): int
    {
        return $this->model->newQuery()
            ->where('render_id', $renderId)
            ->where('attempt_no', $attemptNo)
            ->update([
                'status' => RenderAttemptStatus::SUBMITTED,
                'provider_job_id' => $providerJobId,
                'provider_request_id' => $providerRequestId,
                'provider_submit_response_json' => $submitResponse,
                'submitted_at' => $submittedAt,
            ]);
    }

    public function recordJobOnOwnAttemptIfMissing(string $renderId, int $attemptNo, string $claimToken, string $providerJobId, string $submitResponseJson): int
    {
        return $this->model->newQuery()
            ->where('render_id', $renderId)
            ->where('attempt_no', $attemptNo)
            ->where('claim_token', $claimToken)
            ->whereNull('provider_job_id')
            ->update([
                'provider_job_id' => $providerJobId,
                'provider_submit_response_json' => $submitResponseJson,
            ]);
    }

    public function abandonOpenAttempt(string $renderId, int $attemptNo, string $errorCode, string $errorMessage): int
    {
        return $this->model->newQuery()->where('render_id', $renderId)->where('attempt_no', $attemptNo)->whereNull('completed_at')
            ->update(['status' => RenderAttemptStatus::ABANDONED->value, 'error_code' => $errorCode, 'error_message' => $errorMessage, 'completed_at' => now()]);
    }
}
