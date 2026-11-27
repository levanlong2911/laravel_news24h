<?php

namespace App\Repositories\Interfaces;

use App\Models\VideoProviderSubmissionReceipt;
use Illuminate\Support\Collection;

interface VideoProviderSubmissionReceiptRepositoryInterface extends RepositoryInterface
{
    /**
     * @param  string  $renderId
     * @return bool whether a provider acknowledged a job of the render
     */
    public function existsForRender(string $renderId): bool;

    /**
     * @param  array<int, string>|Collection<int, string>  $renderIds
     * @return bool whether a provider acknowledged a job of one of these renders
     */
    public function existsForRenders(array|Collection $renderIds): bool;

    /**
     * @param  string  $renderId
     * @param  string  $attemptId
     * @param  int  $claimGeneration
     * @return Collection<int, mixed> provider_job_id of every receipt of that attempt and generation, duplicates kept
     */
    public function findJobIdsForAttempt(string $renderId, string $attemptId, int $claimGeneration): Collection;

    /**
     * @param  string  $attemptId
     * @param  string  $providerJobId
     * @return ?VideoProviderSubmissionReceipt the receipt of that job for the attempt
     */
    public function findReceiptForAttemptJob(string $attemptId, string $providerJobId): ?VideoProviderSubmissionReceipt;

    /**
     * @param  array<string, mixed>  $attributes  the raw row, id and timestamps included, inserted without casts or model events; never call it inside a transaction that can roll back
     * @return int the rows inserted, 0 when the insert was ignored
     */
    public function insertReceiptOrIgnore(array $attributes): int;
}
