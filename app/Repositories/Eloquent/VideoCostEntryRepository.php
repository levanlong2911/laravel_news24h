<?php

namespace App\Repositories\Eloquent;

use App\Models\VideoCostEntry;
use App\Repositories\Interfaces\VideoCostEntryRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class VideoCostEntryRepository extends BaseRepository implements VideoCostEntryRepositoryInterface
{
    public function getModel(): string
    {
        return VideoCostEntry::class;
    }

    public function findCostByIdempotencyKey(string $idempotencyKey): ?VideoCostEntry
    {
        return $this->model->newQuery()
            ->where('cost_idempotency_key', $idempotencyKey)
            ->first();
    }

    public function createCostEntry(array $attributes): VideoCostEntry
    {
        return $this->model->newQuery()->create($attributes);
    }

    public function summarizeDesignImageCosts(string $projectId, array $imageIds): Collection
    {
        return $this->designImageEntries($projectId, $imageIds)
            ->selectRaw(
                'entity_id,'
                ."SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.pricing')) "
                ."IN ('reported', 'reconciled', 'free') THEN cost_usd ELSE 0 END) as confirmed,"
                ."SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.pricing')) = 'estimated' "
                ."THEN COALESCE(JSON_EXTRACT(metadata_json, '$.estimated_cost_usd'), cost_usd) ELSE 0 END) as estimated,"
                ."SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.pricing')) = 'estimated' "
                ."THEN 1 ELSE 0 END) as estimates,"
                ."SUM(CASE WHEN JSON_EXTRACT(metadata_json, '$.pricing') IS NULL THEN cost_usd ELSE 0 END) as unclassified,"
                ."SUM(CASE WHEN JSON_EXTRACT(metadata_json, '$.pricing') IS NULL THEN 1 ELSE 0 END) as unclassifieds",
            )
            ->groupBy('entity_id')
            ->get();
    }

    public function pluckUnpricedDesignImageIds(string $projectId, array $imageIds): Collection
    {
        return $this->designImageEntries($projectId, $imageIds)
            ->where('metadata_json->pricing', 'unpriced')
            ->distinct()
            ->pluck('entity_id');
    }

    /**
     * @param  string  $projectId
     * @param  array<int, string>  $imageIds
     * @return Builder the design image entries of the project on these images
     */
    private function designImageEntries(string $projectId, array $imageIds): Builder
    {
        return $this->model->newQuery()
            ->where('project_id', $projectId)
            ->where('entity_type', 'design_image')
            ->whereIn('entity_id', $imageIds);
    }
}
