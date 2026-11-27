<?php

namespace App\Repositories\Eloquent;

use App\Models\VideoProviderSubmissionReceipt;
use App\Repositories\Interfaces\VideoProviderSubmissionReceiptRepositoryInterface;
use Illuminate\Support\Collection;

class VideoProviderSubmissionReceiptRepository extends BaseRepository implements VideoProviderSubmissionReceiptRepositoryInterface
{
    use AppendOnlyRepository;

    public function getModel(): string
    {
        return VideoProviderSubmissionReceipt::class;
    }

    public function existsForRender(string $renderId): bool
    {
        return $this->model->newQuery()->where('render_id', $renderId)->exists();
    }

    public function existsForRenders(array|Collection $renderIds): bool
    {
        return $this->model->newQuery()
            ->whereIn('render_id', $renderIds)
            ->exists();
    }

    public function findJobIdsForAttempt(string $renderId, string $attemptId, int $claimGeneration): Collection
    {
        return $this->model->newQuery()
            ->where('render_id', $renderId)
            ->where('attempt_id', $attemptId)
            ->where('claim_generation', $claimGeneration)
            ->pluck('provider_job_id');
    }

    public function findReceiptForAttemptJob(string $attemptId, string $providerJobId): ?VideoProviderSubmissionReceipt
    {
        return $this->model->newQuery()
            ->where('attempt_id', $attemptId)
            ->where('provider_job_id', $providerJobId)
            ->first();
    }

    public function insertReceiptOrIgnore(array $attributes): int
    {
        return $this->model->newQuery()->insertOrIgnore($attributes);
    }
}
