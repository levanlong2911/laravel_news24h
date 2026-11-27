<?php

namespace App\Repositories\Eloquent;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoPlanningStage;
use App\Repositories\Interfaces\VideoPlanningStageRepositoryInterface;
use App\Services\Video\PlanningStageStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class VideoPlanningStageRepository extends BaseRepository implements VideoPlanningStageRepositoryInterface
{
    /** @var list<string> the columns a run is read with to be reset */
    private const RESET_COLUMNS = ['id', 'status', 'claim_token', 'lease_expires_at'];

    public function getModel(): string
    {
        return VideoPlanningStage::class;
    }

    public function findStage(?string $stageId): ?VideoPlanningStage
    {
        return $this->model->newQuery()->withoutSnapshot()->find($stageId);
    }

    public function findStageOrFail(string $stageId): VideoPlanningStage
    {
        return $this->model->newQuery()->withoutSnapshot()->findOrFail($stageId);
    }

    public function findStageWithSnapshot(?string $stageId): ?VideoPlanningStage
    {
        return $this->model->newQuery()->find($stageId);
    }

    public function findStageWithSnapshotOrFail(?string $stageId): VideoPlanningStage
    {
        return $this->model->newQuery()->findOrFail($stageId);
    }

    public function freshStage(VideoPlanningStage $stage): ?VideoPlanningStage
    {
        return $stage->fresh();
    }

    public function findRequestSnapshot(string $stageId): mixed
    {
        return $this->model->newQuery()->whereKey($stageId)->value('request_snapshot');
    }

    public function findStageForProject(string $projectId, ?string $stageId): ?VideoPlanningStage
    {
        return $this->model->newQuery()->withoutSnapshot()->where('project_id', $projectId)->find($stageId);
    }

    public function findStageForProjectOrFail(string $projectId, ?string $stageId): VideoPlanningStage
    {
        return $this->model->newQuery()->withoutSnapshot()->where('project_id', $projectId)->findOrFail($stageId);
    }

    public function findStageForProjectWithSnapshot(string $projectId, ?string $stageId): ?VideoPlanningStage
    {
        return $this->model->newQuery()->where('project_id', $projectId)->find($stageId);
    }

    public function findStagesForProject(string $projectId, array|Collection $stageIds): Collection
    {
        return $this->model->newQuery()->withoutSnapshot()->where('project_id', $projectId)->whereIn('id', $stageIds)->get();
    }

    public function findStageOfKind(string $projectId, string $stageId, PlanningStageName $stage): ?VideoPlanningStage
    {
        return $this->model->newQuery()->withoutSnapshot()->whereKey($stageId)->where('project_id', $projectId)->where('stage', $stage->value)->first();
    }

    public function findStageOfKindWithSnapshot(string $projectId, ?string $stageId, PlanningStageName $stage): ?VideoPlanningStage
    {
        return $this->model->newQuery()
            ->whereKey($stageId)
            ->where('project_id', $projectId)
            ->where('stage', $stage->value)
            ->first();
    }

    public function findSucceededStageOfKind(string $projectId, ?string $stageId, PlanningStageName $stage): ?VideoPlanningStage
    {
        return $this->model->newQuery()->withoutSnapshot()->whereKey($stageId)->where('project_id', $projectId)
            ->where('stage', $stage->value)->where('status', VideoPlanningStageStatus::SUCCEEDED->value)->first();
    }

    public function findSucceededStageOfKindWithSnapshot(string $projectId, ?string $stageId, PlanningStageName $stage): ?VideoPlanningStage
    {
        return $this->model->newQuery()
            ->whereKey($stageId)
            ->where('project_id', $projectId)
            ->where('stage', $stage->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->first();
    }

    public function findSucceededSourceStage(string $projectId, string $stageId, PlanningStageName $stage): ?VideoPlanningStage
    {
        return $this->model->newQuery()
            ->whereKey($stageId)
            ->where('project_id', $projectId)
            ->where('stage', $stage->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->whereNull('input_json->orphan_of_stage_id')
            ->first();
    }

    public function findSucceededStagesOfKind(string $projectId, PlanningStageName $stage, array|Collection $stageIds): Collection
    {
        return $this->model->newQuery()->withoutSnapshot()->where('project_id', $projectId)
            ->where('stage', $stage->value)->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->whereIn('id', $stageIds)->get();
    }

    public function findStagesOfKind(string $projectId, PlanningStageName $stage, array|Collection $stageIds): Collection
    {
        return $this->model->newQuery()->withoutSnapshot()->where('project_id', $projectId)->where('stage', $stage->value)->whereIn('id', $stageIds)->get();
    }

    public function findSucceededStagesOfKindWithSnapshot(string $projectId, PlanningStageName $stage): Collection
    {
        return $this->model->newQuery()
            ->where('project_id', $projectId)
            ->where('stage', $stage->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->orderByDesc('planning_revision')
            ->get();
    }

    public function findLatestStageOfKindWithSnapshot(string $projectId, PlanningStageName $stage, bool $skipOrphans): ?VideoPlanningStage
    {
        return $this->model->newQuery()
            ->where('project_id', $projectId)
            ->where('stage', $stage->value)
            ->when($skipOrphans, static fn ($query) => $query->whereNull('input_json->orphan_of_stage_id'))
            ->orderByDesc('planning_revision')
            ->first();
    }

    public function findLatestSucceededStageOfKindWithSnapshot(string $projectId, PlanningStageName $stage): ?VideoPlanningStage
    {
        return $this->model->newQuery()
            ->where('project_id', $projectId)
            ->where('stage', $stage->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->orderByDesc('planning_revision')
            ->first();
    }

    public function findSucceededStageForRevision(string $projectId, PlanningStageName $stage, int $revision): ?VideoPlanningStage
    {
        return $this->model->newQuery()
            ->where('project_id', $projectId)
            ->where('stage', $stage->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->orderByDesc('planning_revision')
            ->cursor()
            ->first(static fn (VideoPlanningStage $row) => is_array($row->output_json)
                && (int) ($row->output_json['revision'] ?? 0) === $revision);
    }

    public function pluckInputs(string $projectId, PlanningStageName $stage): Collection
    {
        return $this->model->newQuery()
            ->where('project_id', $projectId)
            ->where('stage', $stage->value)
            ->pluck('input_json');
    }

    public function findCurrentDesign(string $projectId): ?VideoPlanningStage
    {
        return $this->model->newQuery()->withoutSnapshot()
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::YACHT_DESIGN->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->whereNull('input_json->orphan_of_stage_id')
            ->orderByDesc('planning_revision')
            ->first();
    }

    public function succeededStageExists(string $projectId, string $stageId, PlanningStageName $stage): bool
    {
        return $this->model->newQuery()->whereKey($stageId)->where('project_id', $projectId)
            ->where('stage', $stage->value)->where('status', VideoPlanningStageStatus::SUCCEEDED->value)->exists();
    }

    public function succeededStageExistsForInputHashes(string $projectId, PlanningStageName $stage, array $inputHashes): bool
    {
        return $this->model->newQuery()
            ->where('project_id', $projectId)
            ->where('stage', $stage->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->whereIn('input_hash', $inputHashes)
            ->exists();
    }

    public function findLatestSucceededReferencePlan(string $projectId, string $execution): ?VideoPlanningStage
    {
        return $this->model->newQuery()->withoutSnapshot()->where('project_id', $projectId)->where('stage', PlanningStageName::REFERENCE_PROMPT->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)->where('input_json->execution', $execution)->orderByDesc('planning_revision')->first();
    }

    public function findFinishedReferenceReviews(string $projectId): Collection
    {
        return $this->model->newQuery()->withoutSnapshot()->where('project_id', $projectId)->where('stage', PlanningStageName::REFERENCE_REVIEW->value)
            ->whereIn('status', [VideoPlanningStageStatus::SUCCEEDED->value, VideoPlanningStageStatus::FAILED->value])
            ->whereNull('input_json->orphan_of_stage_id')->orderByDesc('planning_revision')->get();
    }

    public function findClaimedReferenceReviews(string $projectId): Collection
    {
        return $this->model->newQuery()->withoutSnapshot()->where('project_id', $projectId)->where('stage', PlanningStageName::REFERENCE_REVIEW->value)
            ->whereIn('status', [VideoPlanningStageStatus::PENDING->value, VideoPlanningStageStatus::RUNNING->value])->whereNotNull('claim_token')->get();
    }

    public function findSucceededReferencePlans(string $projectId, string $mode): Collection
    {
        return $this->model->newQuery()
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::REFERENCE_PROMPT->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->where('input_json->mode', $mode)
            ->orderByDesc('planning_revision')
            ->get();
    }

    public function findWriterRunsOfShot(string $projectId, PlanningStageName $stage, string $shotStageId, string $shotId): array
    {
        return $this->model->newQuery()->withoutSnapshot()->where('project_id', $projectId)
            ->where('stage', $stage->value)->where('input_json->shot_stage_id', $shotStageId)
            ->where('input_json->shot_id', $shotId)->orderByDesc('planning_revision')->get()->all();
    }

    public function findKeyframeComposeRunsOfShot(string $projectId, string $shotStageId, string $shotId): array
    {
        return $this->model->newQuery()->withoutSnapshot()->where('project_id', $projectId)
            ->where('stage', PlanningStageName::KEYFRAME_PROMPT->value)->where('input_json->shot_stage_id', $shotStageId)
            ->where('input_json->shot_id', $shotId)->whereNull('input_json->edit_of')->orderByDesc('planning_revision')->get()->all();
    }

    public function findStageByOperation(string $projectId, PlanningStageName $stage, string $operationId): ?VideoPlanningStage
    {
        return $this->model->newQuery()->withoutSnapshot()->where('project_id', $projectId)->where('stage', $stage->value)
            ->where('input_json->'.PlanningStageStore::METADATA_KEY.'->operation_id', $operationId)->orderByDesc('planning_revision')->first();
    }

    public function findOpenStages(string $projectId, PlanningStageName $stage): Collection
    {
        return $this->model->newQuery()
            ->withoutSnapshot()
            ->where('project_id', $projectId)
            ->where('stage', $stage->value)
            ->whereIn('status', [VideoPlanningStageStatus::PENDING->value, VideoPlanningStageStatus::RUNNING->value])
            ->orderBy('planning_revision')
            ->get();
    }

    public function findSucceededStageByInputHash(string $projectId, PlanningStageName $stage, string $inputHash): ?VideoPlanningStage
    {
        return $this->model->newQuery()
            ->withoutSnapshot()
            ->where('project_id', $projectId)
            ->where('stage', $stage->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->where('input_hash', $inputHash)
            ->orderByDesc('planning_revision')
            ->first();
    }

    public function findSucceededStageByInputHashWithSnapshot(string $projectId, PlanningStageName $stage, string $inputHash): ?VideoPlanningStage
    {
        return $this->model->newQuery()
            ->where('project_id', $projectId)
            ->where('stage', $stage->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->where('input_hash', $inputHash)
            ->orderByDesc('planning_revision')
            ->first();
    }

    public function maxPlanningRevision(string $projectId, PlanningStageName $stage): int
    {
        return (int) $this->model->newQuery()
            ->where('project_id', $projectId)
            ->where('stage', $stage->value)
            ->max('planning_revision');
    }

    public function existsFailedStageWithInputHash(string $projectId, PlanningStageName $stage, string $inputHash): bool
    {
        return $this->model->newQuery()
            ->where('project_id', $projectId)
            ->where('stage', $stage->value)
            ->where('status', VideoPlanningStageStatus::FAILED->value)
            ->where('input_hash', $inputHash)
            ->exists();
    }

    public function createStage(array $attributes): VideoPlanningStage
    {
        return $this->model->newQuery()->create($attributes);
    }

    public function reopenExpiredStage(VideoPlanningStage $stage, array $attributes): void
    {
        $stage->update($attributes);
    }

    public function markQueuedStageRunning(string $stageId, string $claimToken, int $leaseSeconds): int
    {
        return $this->model->newQuery()
            ->whereKey($stageId)
            ->where('claim_token', $claimToken)
            ->where('status', VideoPlanningStageStatus::PENDING->value)
            ->update([
                'status' => VideoPlanningStageStatus::RUNNING->value,
                'claimed_at' => now(),
                'lease_expires_at' => now()->addSeconds($leaseSeconds),
                'started_at' => now(),
            ]);
    }

    public function requeueRunningStage(string $stageId, string $claimToken, string $nextToken, array $retry): int
    {
        $table = $this->model->getTable();
        $meta = '$."'.PlanningStageStore::METADATA_KEY.'"';
        $path = $meta.'."retry"';

        return $this->model->getConnection()->update(
            "UPDATE {$table} SET status = ?, claim_token = ?, claimed_at = NULL, lease_expires_at = NULL, input_json = JSON_SET(JSON_INSERT(input_json, '{$meta}', JSON_OBJECT()), '{$path}', JSON_EXTRACT(?, '$')), updated_at = ? WHERE id = ? AND claim_token = ? AND status = ?",
            [VideoPlanningStageStatus::PENDING->value, $nextToken, json_encode($retry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), now(), $stageId, $claimToken, VideoPlanningStageStatus::RUNNING->value],
        );
    }

    public function finishClaimedStage(string $stageId, string $claimToken, array $attributes): int
    {
        return $this->model->newQuery()
            ->whereKey($stageId)
            ->where('claim_token', $claimToken)
            ->update($attributes + [
                'claim_token' => null,
                'claimed_at' => null,
                'lease_expires_at' => null,
                'finished_at' => now(),
            ]);
    }

    public function renewLeaseWithStream(string $stageId, string $claimToken, array $state, int $leaseSeconds): int
    {
        $table = $this->model->getTable();
        $meta = '$."'.PlanningStageStore::METADATA_KEY.'"';
        $path = $meta.'."stream"';
        $running = VideoPlanningStageStatus::RUNNING->value;

        return $this->model->getConnection()->update(
            "UPDATE {$table} SET input_json = JSON_SET(JSON_INSERT(input_json, '{$meta}', JSON_OBJECT()), '{$path}', JSON_EXTRACT(?, '$')), lease_expires_at = ?, updated_at = ? WHERE id = ? AND claim_token = ? AND status = ?",
            [json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), now()->addSeconds($leaseSeconds), now(), $stageId, $claimToken, $running],
        );
    }

    public function isClaimedRunning(string $stageId, string $claimToken): bool
    {
        return $this->model->newQuery()->whereKey($stageId)->where('claim_token', $claimToken)->where('status', VideoPlanningStageStatus::RUNNING->value)->exists();
    }

    public function findExpiredRunningStages(int $limit): Collection
    {
        return $this->model->newQuery()
            ->where('status', VideoPlanningStageStatus::RUNNING->value)
            ->whereNotNull('claim_token')
            ->where('lease_expires_at', '<', now())
            ->orderBy('lease_expires_at')
            ->limit($limit)
            ->get(['id', 'claim_token']);
    }

    public function failExpiredRunningStage(string $stageId, string $claimToken, array $attributes): int
    {
        return $this->model->newQuery()
            ->whereKey($stageId)
            ->where('claim_token', $claimToken)
            ->where('status', VideoPlanningStageStatus::RUNNING->value)
            ->where('lease_expires_at', '<', now())
            ->update($attributes);
    }

    public function findStalePendingStages(int $limit, int $graceSeconds): Collection
    {
        return $this->model->newQuery()
            ->where('status', VideoPlanningStageStatus::PENDING->value)
            ->whereNotNull('claim_token')
            ->where('updated_at', '<', now()->subSeconds($graceSeconds))
            ->orderBy('updated_at')
            ->limit($limit)
            ->get(['id', 'claim_token']);
    }

    public function failStalePendingStage(string $stageId, string $claimToken, array $attributes): int
    {
        return $this->model->newQuery()
            ->whereKey($stageId)
            ->where('claim_token', $claimToken)
            ->where('status', VideoPlanningStageStatus::PENDING->value)
            ->update($attributes);
    }

    public function findLatestStageOfKindForReset(string $projectId, PlanningStageName $stage): ?VideoPlanningStage
    {
        return $this->model->newQuery()->where('project_id', $projectId)
            ->where('stage', $stage->value)
            ->whereNull('input_json->orphan_of_stage_id')
            ->orderByDesc('planning_revision')
            ->first(self::RESET_COLUMNS);
    }

    public function findStageForReset(string $projectId, string $stageId): ?VideoPlanningStage
    {
        return $this->model->newQuery()->where('project_id', $projectId)->whereKey($stageId)->first(self::RESET_COLUMNS);
    }

    public function releaseStageForReset(string $stageId, string $claimToken, string $status, bool $leaseMustBeOver, array $attributes): int
    {
        return $this->model->newQuery()
            ->whereKey($stageId)
            ->where('claim_token', $claimToken)
            ->where('status', $status)
            ->when($leaseMustBeOver, static fn (Builder $query) => $query->where(static fn (Builder $lease) => $lease->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now())))
            ->update($attributes);
    }

    public function findStageOutputOrFail(string $stageId): VideoPlanningStage
    {
        return $this->model->newQuery()->select(['id', 'output_json'])->findOrFail($stageId);
    }

    public function findFirstPassedDnaRunFor(string $projectId, string $execution, string $phaseId, string $bundleHash, string $inspirationStageId): ?VideoPlanningStage
    {
        return $this->model->newQuery()
            ->passedDna()
            ->where('input_json->execution', $execution)
            ->where('project_id', $projectId)
            ->where('input_json->phase_id', $phaseId)
            ->where('input_json->bundle_hash', $bundleHash)
            ->where('input_json->inspiration_stage_id', $inspirationStageId)
            ->orderBy('created_at')
            ->first(['id', 'input_json']);
    }

    public function findStagesByIdsWithColumns(array|Collection $stageIds, array $columns): Collection
    {
        return $this->model->newQuery()->whereIn('id', $stageIds)->get($columns);
    }

    public function findPassedDnaBatch(string $execution, string $index, string $projectId, array $skippedProjectIds, ?array $after, int $limit): Collection
    {
        return $this->model->newQuery()
            ->passedDna()
            ->where('input_json->execution', $execution)
            ->forceIndex($index)
            ->where('project_id', '!=', $projectId)
            ->when($skippedProjectIds !== [], static fn (Builder $query) => $query->whereNotIn('project_id', $skippedProjectIds))
            ->when($after !== null, static fn (Builder $query) => $query->where(static function (Builder $older) use ($after): void {
                [$finishedAt, $id] = $after;

                if ($finishedAt === null) {
                    $older->whereNull('finished_at')->where('id', '<', $id);
                } else {
                    $older->where('finished_at', '<', $finishedAt)->orWhereNull('finished_at')
                        ->orWhere(static fn (Builder $same) => $same->where('finished_at', $finishedAt)->where('id', '<', $id));
                }
            }))
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'project_id', 'finished_at']);
    }
}
