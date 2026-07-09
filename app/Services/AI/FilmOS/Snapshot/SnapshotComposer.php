<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

use App\Services\AI\FilmOS\DecisionDAG\DecisionDAG;
use App\Services\AI\FilmOS\Intent\DirectorIntent;
use App\Services\AI\FilmOS\Planning\GoalGraph;
use App\Services\AI\FilmOS\Planning\ShotSequencePlan;

/**
 * Orchestrates phase-specific snapshot builders into a single ExecutionSnapshot.
 *
 * compose() accepts a DeterminismManifest and any number of SnapshotSection objects.
 * Each section provides a field map (field name → hash value) matching
 * ExecutionSnapshot constructor parameter names. Sections are merged in order;
 * later sections override earlier ones for the same field key.
 *
 * Adding a new phase (E, F, …) requires:
 *   1. A new XxxSection implements SnapshotSection
 *   2. A new XxxBuilder that returns it
 *   3. Pass the section to compose() at the call site
 *   SnapshotComposer itself does NOT change.
 *
 * Phase contracts:
 *   Phase B — ExecutionLayerBuilder:
 *     executionGraphHash: node id + status.value (NOT elapsedMs, startedAt)
 *     checkpointHash:     ordered list of (nodeId, completedAt-ordinal) — NOT wall-clock time
 *     retrySequenceHash:  ordered list of (nodeId, retryCount) — NOT timestamps
 *
 *   Phase C — ProviderLayerBuilder:
 *     capabilityHash:    (nodeId, capabilityType.value) — NOT display name, description, version
 *     providerRouteHash: (taskId → providerId) — NOT latency, response headers
 *
 *   Phase D — EventLayerBuilder:
 *     eventBusHash: (eventType.value, sourceNodeId) in emission order — NOT timestamp, uuid, payload
 */
final class SnapshotComposer
{
    public function __construct(
        private readonly PlanningSnapshotBuilder $planning = new PlanningSnapshotBuilder(),
    ) {}

    /**
     * Compose a full ExecutionSnapshot from a manifest and one or more sections.
     *
     * @param SnapshotSection ...$sections  Phase A: PlanningSection; Phase B+: additional sections
     */
    public function compose(
        string               $productionId,
        DeterminismManifest  $manifest,
        SnapshotSection      ...$sections,
    ): ExecutionSnapshot {
        $fields = [];
        foreach ($sections as $section) {
            $fields += $section->fields();
        }

        return new ExecutionSnapshot(
            manifest:           $manifest,
            executionId:        'exec_' . $productionId,
            productionId:       $productionId,
            capturedAt:         microtime(true),

            dagHash:            $fields['dagHash'],
            goalGraphHash:      $fields['goalGraphHash'],
            promptHash:         $fields['promptHash'],
            schedulerHash:      $fields['schedulerHash'] ?? null,

            executionGraphHash: $fields['executionGraphHash'] ?? null,
            checkpointHash:     $fields['checkpointHash']     ?? null,
            retrySequenceHash:  $fields['retrySequenceHash']  ?? null,

            capabilityHash:     $fields['capabilityHash']     ?? null,
            providerRouteHash:  $fields['providerRouteHash']  ?? null,

            policyHash:         $fields['policyHash']         ?? null,
            eventBusHash:       $fields['eventBusHash']       ?? null,
        );
    }

    /**
     * Convenience method: run the Phase A planning pipeline and compose in one call.
     *
     * @param  array<string, DirectorIntent>  $intents
     * @param  TaskDescriptor[]               $descriptors
     */
    public function composeFromPlan(
        string               $productionId,
        DeterminismManifest  $manifest,
        DecisionDAG          $dag,
        GoalGraph            $goalGraph,
        ShotSequencePlan     $plan,
        array                $intents,
        array                $descriptors = [],
    ): ExecutionSnapshot {
        $section = $this->planning->build($dag, $goalGraph, $plan, $intents, $descriptors);
        return $this->compose($productionId, $manifest, $section);
    }
}
