<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

use App\Services\AI\FilmOS\DecisionDAG\DecisionDAG;
use App\Services\AI\FilmOS\Intent\DirectorIntent;
use App\Services\AI\FilmOS\Planning\GoalGraph;
use App\Services\AI\FilmOS\Planning\ShotSequencePlan;

/**
 * Builds Phase A (planning layer) snapshot hashes and returns a PlanningSection.
 *
 * Delegates to focused sub-builders:
 *   GraphHash          — DAG and GoalGraph topology (HashableNode / HashableEdge)
 *   PromptHashBuilder  — ExecutionContext canonical fields (NOT rendered string)
 *   PolicyHashBuilder  — PolicyDecision canonical fields (NOT audit trail)
 *   SchedulerHashBuilder — TaskDescriptor topology (NOT FilmTask payload)
 *
 * Phase B will add ExecutionLayerBuilder → ExecutionSection.
 * Phase C will add ProviderLayerBuilder  → ProviderSection.
 * Phase D will add EventLayerBuilder     → EventSection.
 */
final class PlanningSnapshotBuilder
{
    public function __construct(
        private readonly GraphHash             $graphHash       = new GraphHash(),
        private readonly PromptHashBuilder     $promptBuilder   = new PromptHashBuilder(),
        private readonly PolicyHashBuilder     $policyBuilder   = new PolicyHashBuilder(),
        private readonly SchedulerHashBuilder  $schedulerBuilder = new SchedulerHashBuilder(),
    ) {}

    /**
     * @param  array<string, DirectorIntent>  $intents      subGoalId → DirectorIntent
     * @param  TaskDescriptor[]               $descriptors  empty = no schedulerHash
     */
    public function build(
        DecisionDAG      $dag,
        GoalGraph        $goalGraph,
        ShotSequencePlan $plan,
        array            $intents,
        array            $descriptors = [],
    ): PlanningSection {
        return new PlanningSection(
            dagHash:       $this->graphHash->of($dag),
            goalGraphHash: $this->graphHash->of($goalGraph),
            promptHash:    $this->promptBuilder->build($intents),
            schedulerHash: $this->schedulerBuilder->build($descriptors),
            policyHash:    $this->policyBuilder->build($plan->policyDecision),
        );
    }
}
