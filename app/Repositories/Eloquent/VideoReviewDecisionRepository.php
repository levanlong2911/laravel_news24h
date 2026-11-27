<?php

namespace App\Repositories\Eloquent;

use App\Models\VideoReviewDecision;
use App\Repositories\Interfaces\VideoReviewDecisionRepositoryInterface;
use Illuminate\Support\Collection;

class VideoReviewDecisionRepository extends BaseRepository implements VideoReviewDecisionRepositoryInterface
{
    use AppendOnlyRepository;

    public function getModel(): string
    {
        return VideoReviewDecision::class;
    }

    public function createDecision(array $attributes): VideoReviewDecision
    {
        return $this->model->newQuery()->create($attributes);
    }

    public function maxRevisionFor(string $entityType, string $entityId): int
    {
        return (int) $this->model->newQuery()->where('entity_type', $entityType)->where('entity_id', $entityId)->max('revision');
    }

    public function findApprovalFor(string $projectId, ?string $decisionId, string $entityType, string $entityId): ?VideoReviewDecision
    {
        return $this->model->newQuery()->whereKey($decisionId)->where('project_id', $projectId)
            ->where('entity_type', $entityType)->where('entity_id', $entityId)->where('decision', 'approved')->first();
    }

    public function findDecisionFor(string $projectId, ?string $decisionId, string $entityType, string $entityId): ?VideoReviewDecision
    {
        return $this->model->newQuery()->whereKey($decisionId)->where('project_id', $projectId)
            ->where('entity_type', $entityType)->where('entity_id', $entityId)->first();
    }

    public function findLatestForEntity(string $entityType, string $entityId): ?VideoReviewDecision
    {
        return $this->model->newQuery()->where('entity_type', $entityType)->where('entity_id', $entityId)->orderByDesc('revision')->first();
    }

    public function findLatestForProjectEntity(string $projectId, string $entityType, string $entityId): ?VideoReviewDecision
    {
        return $this->model->newQuery()
            ->where('project_id', $projectId)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->orderByDesc('revision')
            ->first();
    }

    public function findLatestApprovedIdForEntity(string $projectId, string $entityType, string $entityId): ?string
    {
        return $this->model->newQuery()->where('project_id', $projectId)->where('entity_type', $entityType)->where('entity_id', $entityId)
            ->where('decision', 'approved')->orderByDesc('revision')->value('id');
    }

    public function findApprovedIdsGroupedByEntity(string $projectId, array $entityTypes, array|Collection $entityIds): Collection
    {
        return $this->model->newQuery()->where('project_id', $projectId)->whereIn('entity_type', $entityTypes)
            ->where('decision', 'approved')->whereIn('entity_id', $entityIds)->get(['id', 'entity_id'])->groupBy('entity_id');
    }

    public function findApprovedByIds(string $projectId, string $entityType, array|Collection $decisionIds): Collection
    {
        return $this->model->newQuery()->where('project_id', $projectId)->where('entity_type', $entityType)->where('decision', 'approved')->whereIn('id', $decisionIds)->get();
    }

    public function findApprovedDecisionsByIds(array|Collection $decisionIds, string $entityType): Collection
    {
        return $this->model->newQuery()->whereIn('id', $decisionIds)
            ->where('entity_type', $entityType)->where('decision', 'approved')->get();
    }

    public function isApprovalOf(string $decisionId, string $entityType, string $entityId): bool
    {
        return $this->model->newQuery()->whereKey($decisionId)->where('entity_type', $entityType)
            ->where('entity_id', $entityId)->where('decision', 'approved')->exists();
    }

    public function findShotDecisions(array|Collection $shotIds): Collection
    {
        return $this->model->newQuery()->where('entity_type', 'shot')->whereIn('entity_id', $shotIds)->orderBy('revision')->get();
    }

    public function findLatestShotDecisionNamingRender(string $shotId, string $renderId): ?VideoReviewDecision
    {
        return $this->model->newQuery()
            ->where('entity_type', 'shot')
            ->where('entity_id', $shotId)
            ->where(static fn ($query) => $query->where('metadata_json->render_id', $renderId)->orWhere('metadata_json->selected_render_id', $renderId))
            ->orderByDesc('revision')
            ->first();
    }

    public function findLatestShotApprovalSelectingRender(string $shotId, string $renderId): ?VideoReviewDecision
    {
        return $this->model->newQuery()
            ->where('entity_type', 'shot')
            ->where('entity_id', $shotId)
            ->where('decision', 'approved')
            ->where('metadata_json->selected_render_id', $renderId)
            ->orderByDesc('revision')
            ->first();
    }
}
