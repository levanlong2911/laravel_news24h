<?php

namespace App\Repositories\Interfaces;

use App\Enums\PlanningStageName;
use App\Models\VideoPlanningStage;
use Illuminate\Support\Collection;

interface VideoPlanningStageRepositoryInterface extends RepositoryInterface
{
    /**
     * @param  ?string  $stageId
     * @return ?VideoPlanningStage the stage without request_snapshot, of any project
     */
    public function findStage(?string $stageId): ?VideoPlanningStage;

    /**
     * @param  string  $stageId
     * @return VideoPlanningStage the stage without request_snapshot, of any project
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findStageOrFail(string $stageId): VideoPlanningStage;

    /**
     * @param  ?string  $stageId  null still runs the lookup (id is null)
     * @return ?VideoPlanningStage the full stage row, of any project
     */
    public function findStageWithSnapshot(?string $stageId): ?VideoPlanningStage;

    /**
     * @param  ?string  $stageId
     * @return VideoPlanningStage the full stage row, of any project
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findStageWithSnapshotOrFail(?string $stageId): VideoPlanningStage;

    /**
     * @param  VideoPlanningStage  $stage
     * @return ?VideoPlanningStage the stage read again in full, a new instance; null when it is gone
     */
    public function freshStage(VideoPlanningStage $stage): ?VideoPlanningStage;

    /**
     * @param  string  $stageId
     * @return mixed request_snapshot as stored, null when the stage or the value does not exist
     */
    public function findRequestSnapshot(string $stageId): mixed;

    /**
     * @param  string  $projectId
     * @param  ?string  $stageId  null still runs the lookup (id is null)
     * @return ?VideoPlanningStage the stage of the project without request_snapshot
     */
    public function findStageForProject(string $projectId, ?string $stageId): ?VideoPlanningStage;

    /**
     * @param  string  $projectId
     * @param  ?string  $stageId
     * @return VideoPlanningStage the stage of the project without request_snapshot
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findStageForProjectOrFail(string $projectId, ?string $stageId): VideoPlanningStage;

    /**
     * @param  string  $projectId
     * @param  ?string  $stageId  null still runs the lookup (id is null)
     * @return ?VideoPlanningStage the full stage row of the project
     */
    public function findStageForProjectWithSnapshot(string $projectId, ?string $stageId): ?VideoPlanningStage;

    /**
     * @param  string  $projectId
     * @param  array<int, string>|Collection<int, string>  $stageIds  an empty list still runs the query
     * @return Collection<int, VideoPlanningStage> the stages of the project with these ids, without request_snapshot, in no given order
     */
    public function findStagesForProject(string $projectId, array|Collection $stageIds): Collection;

    /**
     * @param  string  $projectId
     * @param  string  $stageId
     * @param  PlanningStageName  $stage
     * @return ?VideoPlanningStage the stage of the project and step whatever its status, without request_snapshot
     */
    public function findStageOfKind(string $projectId, string $stageId, PlanningStageName $stage): ?VideoPlanningStage;

    /**
     * @param  string  $projectId
     * @param  ?string  $stageId
     * @param  PlanningStageName  $stage
     * @return ?VideoPlanningStage the full stage row of the project and step whatever its status
     */
    public function findStageOfKindWithSnapshot(string $projectId, ?string $stageId, PlanningStageName $stage): ?VideoPlanningStage;

    /**
     * @param  string  $projectId
     * @param  ?string  $stageId
     * @param  PlanningStageName  $stage
     * @return ?VideoPlanningStage the succeeded stage of the project and step, without request_snapshot
     */
    public function findSucceededStageOfKind(string $projectId, ?string $stageId, PlanningStageName $stage): ?VideoPlanningStage;

    /**
     * @param  string  $projectId
     * @param  ?string  $stageId  null still runs the lookup (id is null)
     * @param  PlanningStageName  $stage
     * @return ?VideoPlanningStage the full succeeded stage row of the project and step, orphan attempts included
     */
    public function findSucceededStageOfKindWithSnapshot(string $projectId, ?string $stageId, PlanningStageName $stage): ?VideoPlanningStage;

    /**
     * @param  string  $projectId
     * @param  string  $stageId
     * @param  PlanningStageName  $stage
     * @return ?VideoPlanningStage the full succeeded stage row of the project and step that is not an orphan attempt
     */
    public function findSucceededSourceStage(string $projectId, string $stageId, PlanningStageName $stage): ?VideoPlanningStage;

    /**
     * @param  string  $projectId
     * @param  PlanningStageName  $stage
     * @param  array<int, string>|Collection<int, string>  $stageIds  an empty list still runs the query
     * @return Collection<int, VideoPlanningStage> the succeeded stages of the project and step with these ids, without request_snapshot
     */
    public function findSucceededStagesOfKind(string $projectId, PlanningStageName $stage, array|Collection $stageIds): Collection;

    /**
     * @param  string  $projectId
     * @param  PlanningStageName  $stage
     * @param  array<int, string>|Collection<int, string>  $stageIds  an empty list still runs the query
     * @return Collection<int, VideoPlanningStage> the stages of the project and step with these ids whatever their status, without request_snapshot
     */
    public function findStagesOfKind(string $projectId, PlanningStageName $stage, array|Collection $stageIds): Collection;

    /**
     * @param  string  $projectId
     * @param  PlanningStageName  $stage
     * @return Collection<int, VideoPlanningStage> every full succeeded stage row of the project and step, newest planning revision first
     */
    public function findSucceededStagesOfKindWithSnapshot(string $projectId, PlanningStageName $stage): Collection;

    /**
     * @param  string  $projectId
     * @param  PlanningStageName  $stage
     * @param  bool  $skipOrphans  true leaves out the attempts recorded apart (input_json.orphan_of_stage_id set)
     * @return ?VideoPlanningStage the full row of the newest stage of the project and step whatever its status
     */
    public function findLatestStageOfKindWithSnapshot(string $projectId, PlanningStageName $stage, bool $skipOrphans): ?VideoPlanningStage;

    /**
     * @param  string  $projectId
     * @param  PlanningStageName  $stage
     * @return ?VideoPlanningStage the full row of the newest succeeded stage of the project and step, orphan attempts included
     */
    public function findLatestSucceededStageOfKindWithSnapshot(string $projectId, PlanningStageName $stage): ?VideoPlanningStage;

    /**
     * @param  string  $projectId
     * @param  PlanningStageName  $stage
     * @param  int  $revision  the revision the stage output declares
     * @return ?VideoPlanningStage the full row of the newest succeeded stage of the project and step whose output_json.revision is $revision, read with a cursor
     */
    public function findSucceededStageForRevision(string $projectId, PlanningStageName $stage, int $revision): ?VideoPlanningStage;

    /**
     * @param  string  $projectId
     * @param  PlanningStageName  $stage
     * @return Collection<int, mixed> input_json of every stage of the project and step, as the model casts it
     */
    public function pluckInputs(string $projectId, PlanningStageName $stage): Collection;

    /**
     * @param  string  $projectId
     * @return ?VideoPlanningStage the newest succeeded design stage of the project that is not an orphan attempt, without request_snapshot
     */
    public function findCurrentDesign(string $projectId): ?VideoPlanningStage;

    /**
     * @param  string  $projectId
     * @param  string  $stageId
     * @param  PlanningStageName  $stage
     * @return bool whether the project has this succeeded stage of the step
     */
    public function succeededStageExists(string $projectId, string $stageId, PlanningStageName $stage): bool;

    /**
     * @param  string  $projectId
     * @param  PlanningStageName  $stage
     * @param  array<int, string>  $inputHashes
     * @return bool whether a succeeded stage of the project and step has one of these input hashes
     */
    public function succeededStageExistsForInputHashes(string $projectId, PlanningStageName $stage, array $inputHashes): bool;

    /**
     * @param  string  $projectId
     * @param  string  $execution  the input_json.execution the plan was made under
     * @return ?VideoPlanningStage the newest succeeded reference prompt stage of the project made under that execution, without request_snapshot
     */
    public function findLatestSucceededReferencePlan(string $projectId, string $execution): ?VideoPlanningStage;

    /**
     * @param  string  $projectId
     * @return Collection<int, VideoPlanningStage> the succeeded or failed reference review stages of the project that are not orphan attempts, newest planning revision first, without request_snapshot
     */
    public function findFinishedReferenceReviews(string $projectId): Collection;

    /**
     * @param  string  $projectId
     * @return Collection<int, VideoPlanningStage> the pending or running reference review stages of the project that hold a claim, without request_snapshot
     */
    public function findClaimedReferenceReviews(string $projectId): Collection;

    /**
     * @param  string  $projectId
     * @param  string  $mode  the input_json.mode of the plan
     * @return Collection<int, VideoPlanningStage> every full succeeded reference prompt stage row of the project in that mode, newest planning revision first
     */
    public function findSucceededReferencePlans(string $projectId, string $mode): Collection;

    /**
     * @param  string  $projectId
     * @param  PlanningStageName  $stage  the step of the writer
     * @param  string  $shotStageId
     * @param  string  $shotId
     * @return list<VideoPlanningStage> every run of the writer for the shot of that shot plan, newest planning revision first, without request_snapshot
     */
    public function findWriterRunsOfShot(string $projectId, PlanningStageName $stage, string $shotStageId, string $shotId): array;

    /**
     * @param  string  $projectId
     * @param  string  $shotStageId
     * @param  string  $shotId
     * @return list<VideoPlanningStage> every keyframe prompt run for the shot of that shot plan that is not an edit (input_json.edit_of null), newest planning revision first, without request_snapshot
     */
    public function findKeyframeComposeRunsOfShot(string $projectId, string $shotStageId, string $shotId): array;

    /**
     * @param  string  $projectId
     * @param  PlanningStageName  $stage
     * @param  string  $operationId  input_json._meta.operation_id
     * @return ?VideoPlanningStage the newest stage of the project and step recorded under that operation, without request_snapshot
     */
    public function findStageByOperation(string $projectId, PlanningStageName $stage, string $operationId): ?VideoPlanningStage;

    /**
     * @param  string  $projectId
     * @param  PlanningStageName  $stage
     * @return Collection<int, VideoPlanningStage> the pending or running stages of the project and step, oldest planning revision first, without request_snapshot
     */
    public function findOpenStages(string $projectId, PlanningStageName $stage): Collection;

    /**
     * @param  string  $projectId
     * @param  PlanningStageName  $stage
     * @param  string  $inputHash
     * @return ?VideoPlanningStage the newest succeeded stage of the project and step with this input hash, without request_snapshot
     */
    public function findSucceededStageByInputHash(string $projectId, PlanningStageName $stage, string $inputHash): ?VideoPlanningStage;

    /**
     * @param  string  $projectId
     * @param  PlanningStageName  $stage
     * @param  string  $inputHash
     * @return ?VideoPlanningStage the full row of the newest succeeded stage of the project and step with this input hash
     */
    public function findSucceededStageByInputHashWithSnapshot(string $projectId, PlanningStageName $stage, string $inputHash): ?VideoPlanningStage;

    /**
     * @param  string  $projectId
     * @param  PlanningStageName  $stage
     * @return int the highest planning revision of the project and step, 0 when it has none
     */
    public function maxPlanningRevision(string $projectId, PlanningStageName $stage): int;

    /**
     * @param  string  $projectId
     * @param  PlanningStageName  $stage
     * @param  string  $inputHash
     * @return bool whether a failed stage of the project and step has this input hash
     */
    public function existsFailedStageWithInputHash(string $projectId, PlanningStageName $stage, string $inputHash): bool;

    /**
     * @param  array<string, mixed>  $attributes  fillable attributes, cast by the model
     * @return VideoPlanningStage the new stage with its generated id
     */
    public function createStage(array $attributes): VideoPlanningStage;

    /**
     * @param  VideoPlanningStage  $stage  the expired running stage read under the project lock; it is updated in place
     * @param  array<string, mixed>  $attributes  fillable attributes, written through the model (casts, updated_at)
     * @return void
     */
    public function reopenExpiredStage(VideoPlanningStage $stage, array $attributes): void;

    /**
     * @param  string  $stageId
     * @param  string  $claimToken
     * @param  int  $leaseSeconds
     * @return int the rows moved from pending under this claim to running with a lease of $leaseSeconds
     */
    public function markQueuedStageRunning(string $stageId, string $claimToken, int $leaseSeconds): int;

    /**
     * @param  string  $stageId
     * @param  string  $claimToken  the claim the running stage must still hold
     * @param  string  $nextToken  the claim the stage is queued again under
     * @param  array<string, mixed>  $retry  stored at input_json._meta.retry
     * @return int the rows moved back to pending
     */
    public function requeueRunningStage(string $stageId, string $claimToken, string $nextToken, array $retry): int;

    /**
     * @param  string  $stageId
     * @param  string  $claimToken
     * @param  array<string, mixed>  $attributes  the outcome; claim_token, claimed_at and lease_expires_at are cleared and finished_at set to now unless $attributes names them
     * @return int the rows finished under this claim
     */
    public function finishClaimedStage(string $stageId, string $claimToken, array $attributes): int;

    /**
     * @param  string  $stageId
     * @param  string  $claimToken
     * @param  array<string, mixed>  $state  stored at input_json._meta.stream
     * @param  int  $leaseSeconds
     * @return int the rows changed: the progress stored and the lease renewed while the stage runs under this claim
     */
    public function renewLeaseWithStream(string $stageId, string $claimToken, array $state, int $leaseSeconds): int;

    /**
     * @param  string  $stageId
     * @param  string  $claimToken
     * @return bool whether the stage is running under this claim
     */
    public function isClaimedRunning(string $stageId, string $claimToken): bool;

    /**
     * @param  int  $limit
     * @return Collection<int, VideoPlanningStage> id and claim_token of the running stages holding a claim whose lease ran out, oldest lease first
     */
    public function findExpiredRunningStages(int $limit): Collection;

    /**
     * @param  string  $stageId
     * @param  string  $claimToken
     * @param  array<string, mixed>  $attributes
     * @return int the rows written while the stage still runs under this claim with its lease run out
     */
    public function failExpiredRunningStage(string $stageId, string $claimToken, array $attributes): int;

    /**
     * @param  int  $limit
     * @param  int  $graceSeconds
     * @return Collection<int, VideoPlanningStage> id and claim_token of the pending stages holding a claim not touched for $graceSeconds, oldest first
     */
    public function findStalePendingStages(int $limit, int $graceSeconds): Collection;

    /**
     * @param  string  $stageId
     * @param  string  $claimToken
     * @param  array<string, mixed>  $attributes
     * @return int the rows written while the stage is still pending under this claim
     */
    public function failStalePendingStage(string $stageId, string $claimToken, array $attributes): int;

    /**
     * @param  string  $projectId
     * @param  PlanningStageName  $stage
     * @return ?VideoPlanningStage id, status, claim_token and lease_expires_at of the newest stage of the project and step that is not an orphan attempt
     */
    public function findLatestStageOfKindForReset(string $projectId, PlanningStageName $stage): ?VideoPlanningStage;

    /**
     * @param  string  $projectId
     * @param  string  $stageId
     * @return ?VideoPlanningStage id, status, claim_token and lease_expires_at of the stage of the project
     */
    public function findStageForReset(string $projectId, string $stageId): ?VideoPlanningStage;

    /**
     * @param  string  $stageId
     * @param  string  $claimToken  the claim read
     * @param  string  $status  the status read
     * @param  bool  $leaseMustBeOver  true writes only when the lease is null or has run out
     * @param  array<string, mixed>  $attributes
     * @return int the rows released
     */
    public function releaseStageForReset(string $stageId, string $claimToken, string $status, bool $leaseMustBeOver, array $attributes): int;

    /**
     * @param  string  $stageId
     * @return VideoPlanningStage id and output_json of the stage, of any project
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findStageOutputOrFail(string $stageId): VideoPlanningStage;

    /**
     * @param  string  $projectId
     * @param  string  $execution  the execution the DNA runs pinned
     * @param  string  $phaseId
     * @param  string  $bundleHash
     * @param  string  $inspirationStageId
     * @return ?VideoPlanningStage id and input_json of the oldest passed DNA run of the project with this execution, prompt revision, contract and Phase 1 run
     */
    public function findFirstPassedDnaRunFor(string $projectId, string $execution, string $phaseId, string $bundleHash, string $inspirationStageId): ?VideoPlanningStage;

    /**
     * @param  array<int, mixed>|Collection<int, mixed>  $stageIds
     * @param  list<string>  $columns
     * @return Collection<int, VideoPlanningStage> the stages with these ids, of any project, read with these columns only and in no set order
     */
    public function findStagesByIdsWithColumns(array|Collection $stageIds, array $columns): Collection;

    /**
     * @param  string  $execution  the execution the DNA runs pinned
     * @param  string  $index  the index the read is held to
     * @param  string  $projectId  the project whose runs are left out
     * @param  list<string>  $skippedProjectIds  more projects whose runs are left out; empty leaves no other out
     * @param  ?array{0: mixed, 1: string}  $after  the raw finished_at and the id of the last run of the batch before; null reads from the newest run
     * @param  int  $limit
     * @return Collection<int, VideoPlanningStage> id, project_id and finished_at of the next passed DNA runs of other projects, newest finished first and by id among runs finished together
     */
    public function findPassedDnaBatch(string $execution, string $index, string $projectId, array $skippedProjectIds, ?array $after, int $limit): Collection;
}
