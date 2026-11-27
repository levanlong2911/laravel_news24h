<?php

namespace App\Repositories\Interfaces;

use App\Models\VideoReviewDecision;
use Illuminate\Support\Collection;

interface VideoReviewDecisionRepositoryInterface extends RepositoryInterface
{
    /**
     * @param  array<string, mixed>  $attributes  fillable attributes; decisions are only ever added, never changed or removed
     * @return VideoReviewDecision the new decision with its generated id
     */
    public function createDecision(array $attributes): VideoReviewDecision;

    /**
     * @param  string  $entityType
     * @param  string  $entityId
     * @return int the highest revision of the decisions on the entity, 0 when it has none; read under the project lock of the caller
     */
    public function maxRevisionFor(string $entityType, string $entityId): int;

    /**
     * @param  string  $projectId
     * @param  ?string  $decisionId  null still runs the lookup (id is null)
     * @param  string  $entityType
     * @param  string  $entityId
     * @return ?VideoReviewDecision the decision when it approves that entity of the project
     */
    public function findApprovalFor(string $projectId, ?string $decisionId, string $entityType, string $entityId): ?VideoReviewDecision;

    /**
     * @param  string  $projectId
     * @param  ?string  $decisionId  null still runs the lookup (id is null)
     * @param  string  $entityType
     * @param  string  $entityId
     * @return ?VideoReviewDecision the decision when it is on that entity of the project, whatever it decided
     */
    public function findDecisionFor(string $projectId, ?string $decisionId, string $entityType, string $entityId): ?VideoReviewDecision;

    /**
     * @param  string  $entityType
     * @param  string  $entityId
     * @return ?VideoReviewDecision the decision of the highest revision on the entity, of any project
     */
    public function findLatestForEntity(string $entityType, string $entityId): ?VideoReviewDecision;

    /**
     * @param  string  $projectId
     * @param  string  $entityType
     * @param  string  $entityId
     * @return ?VideoReviewDecision the decision of the highest revision on that entity of the project, whatever it decided
     */
    public function findLatestForProjectEntity(string $projectId, string $entityType, string $entityId): ?VideoReviewDecision;

    /**
     * @param  string  $projectId
     * @param  string  $entityType
     * @param  string  $entityId
     * @return ?string the id of the approval of the highest revision on that entity of the project
     */
    public function findLatestApprovedIdForEntity(string $projectId, string $entityType, string $entityId): ?string;

    /**
     * @param  string  $projectId
     * @param  list<string>  $entityTypes
     * @param  array<int, string>|Collection<int, string>  $entityIds
     * @return Collection<string, Collection<int, VideoReviewDecision>> id and entity_id of the approvals of the project on these entities, grouped by entity_id
     */
    public function findApprovedIdsGroupedByEntity(string $projectId, array $entityTypes, array|Collection $entityIds): Collection;

    /**
     * @param  string  $projectId
     * @param  string  $entityType
     * @param  array<int, string>|Collection<int, string>  $decisionIds
     * @return Collection<int, VideoReviewDecision> the approvals of the project of that entity type with these ids
     */
    public function findApprovedByIds(string $projectId, string $entityType, array|Collection $decisionIds): Collection;

    /**
     * @param  array<int, string>|Collection<int, string>  $decisionIds
     * @param  string  $entityType
     * @return Collection<int, VideoReviewDecision> the approvals of that entity type with these ids, of any project
     */
    public function findApprovedDecisionsByIds(array|Collection $decisionIds, string $entityType): Collection;

    /**
     * @param  string  $decisionId
     * @param  string  $entityType
     * @param  string  $entityId
     * @return bool whether the decision approves that entity
     */
    public function isApprovalOf(string $decisionId, string $entityType, string $entityId): bool;

    /**
     * @param  array<int, string>|Collection<int, string>  $shotIds
     * @return Collection<int, VideoReviewDecision> the decisions on these shots, by revision
     */
    public function findShotDecisions(array|Collection $shotIds): Collection;

    /**
     * @param  string  $shotId
     * @param  string  $renderId
     * @return ?VideoReviewDecision the decision of the highest revision on the shot whose metadata names the render (render_id or selected_render_id), whatever it decided
     */
    public function findLatestShotDecisionNamingRender(string $shotId, string $renderId): ?VideoReviewDecision;

    /**
     * @param  string  $shotId
     * @param  string  $renderId
     * @return ?VideoReviewDecision the approval of the highest revision on the shot that selected the render (metadata selected_render_id)
     */
    public function findLatestShotApprovalSelectingRender(string $shotId, string $renderId): ?VideoReviewDecision;
}
