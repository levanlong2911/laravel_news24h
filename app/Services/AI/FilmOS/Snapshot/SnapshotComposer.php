<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

use App\Services\AI\FilmOS\DecisionDAG\DecisionDAG;
use App\Services\AI\FilmOS\Intent\DirectorIntent;
use App\Services\AI\FilmOS\Kernel\FilmTask;
use App\Services\AI\FilmOS\Planning\GoalGraph;
use App\Services\AI\FilmOS\Planning\ShotSequencePlan;

/**
 * Orchestrates phase-specific snapshot sub-builders into a single ExecutionSnapshot.
 *
 * Each phase adds a dedicated sub-builder injected via the constructor.
 * The composer merges their outputs and constructs the final immutable snapshot.
 *
 * Current phases:
 *   Phase A (now):  PlanningSnapshotBuilder
 *                   → dagHash, goalGraphHash, promptHash, schedulerHash, policyHash
 *
 *   Phase B (next): ExecutionLayerBuilder (wire ExecutionRuntime)
 *                   → executionGraphHash, checkpointHash, retrySequenceHash
 *
 *   Phase C:        ProviderLayerBuilder (wire CapabilityRegistry + ProviderRegistry)
 *                   → capabilityHash (nodeId + capabilityType.value only, NOT name/version)
 *                   → providerRouteHash (taskId → providerId mapping)
 *
 *   Phase D:        EventLayerBuilder (wire EventBus sequence capture)
 *                   → eventBusHash (eventType.value + sourceNodeId only, NOT timestamp/uuid/payload)
 */
final class SnapshotComposer
{
    public function __construct(
        private readonly PlanningSnapshotBuilder $planning = new PlanningSnapshotBuilder(),
    ) {}

    /**
     * Compose a full ExecutionSnapshot from all available phase builders.
     *
     * @param  array<string, DirectorIntent>  $intents  subGoalId → DirectorIntent
     * @param  FilmTask[]                     $tasks    Kernel tasks in submission order (empty = no schedulerHash)
     */
    public function compose(
        string $productionId,
        DecisionDAG $dag,
        GoalGraph $goalGraph,
        ShotSequencePlan $plan,
        array $intents,
        array $tasks = [],
    ): ExecutionSnapshot {
        $phase = $this->planning->build($dag, $goalGraph, $plan, $intents, $tasks);

        return new ExecutionSnapshot(
            schemaVersion:      1,
            executionId:        'exec_' . $productionId,
            productionId:       $productionId,
            capturedAt:         microtime(true),

            // Phase A — Planning layer
            dagHash:            $phase['dagHash'],
            goalGraphHash:      $phase['goalGraphHash'],
            promptHash:         $phase['promptHash'],
            schedulerHash:      $phase['schedulerHash'],

            // Phase B — wire ExecutionLayerBuilder
            executionGraphHash: null,
            checkpointHash:     null,
            retrySequenceHash:  null,

            // Phase C — wire ProviderLayerBuilder
            capabilityHash:     null,
            providerRouteHash:  null,

            // Phase A (from PlanningSnapshotBuilder)
            policyHash:         $phase['policyHash'],

            // Phase D — wire EventLayerBuilder
            eventBusHash:       null,
        );
    }
}
