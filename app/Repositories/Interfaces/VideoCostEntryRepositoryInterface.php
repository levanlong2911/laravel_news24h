<?php

namespace App\Repositories\Interfaces;

use App\Models\VideoCostEntry;
use Illuminate\Support\Collection;

interface VideoCostEntryRepositoryInterface extends RepositoryInterface
{
    /**
     * @param  string  $idempotencyKey
     * @return ?VideoCostEntry the entry recorded under the key, not locked
     */
    public function findCostByIdempotencyKey(string $idempotencyKey): ?VideoCostEntry;

    /**
     * @param  array<string, mixed>  $attributes  fillable attributes
     * @return VideoCostEntry the new entry with its generated id
     */
    public function createCostEntry(array $attributes): VideoCostEntry;

    /**
     * @param  string  $projectId
     * @param  array<int, string>  $imageIds
     * @return Collection<int, VideoCostEntry> per image of the project: entity_id with the sums confirmed, estimated, estimates, unclassified and unclassifieds of its design image entries
     */
    public function summarizeDesignImageCosts(string $projectId, array $imageIds): Collection;

    /**
     * @param  string  $projectId
     * @param  array<int, string>  $imageIds
     * @return Collection<int, mixed> the distinct images of the project among these that have an unpriced design image entry
     */
    public function pluckUnpricedDesignImageIds(string $projectId, array $imageIds): Collection;
}
