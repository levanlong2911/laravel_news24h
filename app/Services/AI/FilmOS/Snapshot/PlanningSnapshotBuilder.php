<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

use App\Services\AI\FilmOS\DecisionDAG\DecisionDAG;
use App\Services\AI\FilmOS\Intent\DirectorIntent;
use App\Services\AI\FilmOS\Kernel\FilmTask;
use App\Services\AI\FilmOS\Planning\GoalGraph;
use App\Services\AI\FilmOS\Planning\ShotSequencePlan;

/**
 * Builds Phase A (Planning layer) snapshot hashes.
 *
 * Responsible for:
 *   dagHash       — DecisionDAG topology (structural only via GraphHashable)
 *   goalGraphHash — GoalGraph topology (structural only via GraphHashable)
 *   promptHash    — ExecutionContext canonical fields (NOT rendered string)
 *   schedulerHash — FilmTask topology (id, type, priority, deps, deadline)
 *   policyHash    — PolicyDecision canonical fields (NOT audit trail)
 *
 * Phase B will add ExecutionLayerBuilder for executionGraphHash / checkpointHash / retrySequenceHash.
 * Phase C will add ProviderLayerBuilder for capabilityHash / providerRouteHash.
 * Phase D will add EventLayerBuilder for eventBusHash.
 */
final class PlanningSnapshotBuilder
{
    /**
     * @param  array<string, DirectorIntent>  $intents  subGoalId → DirectorIntent
     * @param  FilmTask[]                     $tasks    Kernel tasks in submission order (empty = no schedulerHash)
     *
     * @return array{
     *   dagHash: string,
     *   goalGraphHash: string,
     *   promptHash: string,
     *   schedulerHash: string|null,
     *   policyHash: string|null,
     * }
     */
    public function build(
        DecisionDAG $dag,
        GoalGraph $goalGraph,
        ShotSequencePlan $plan,
        array $intents,
        array $tasks = [],
    ): array {
        $policyHash = null;
        if ($plan->policyDecision !== null) {
            $policyHash = hash('sha256', json_encode(
                $plan->policyDecision->toCanonicalArray(),
                JSON_THROW_ON_ERROR,
            ));
        }

        return [
            'dagHash'       => GraphHash::of($dag),
            'goalGraphHash' => GraphHash::of($goalGraph),
            'promptHash'    => GraphHash::ofIntents($intents),
            'schedulerHash' => !empty($tasks) ? GraphHash::ofTasks($tasks) : null,
            'policyHash'    => $policyHash,
        ];
    }
}
