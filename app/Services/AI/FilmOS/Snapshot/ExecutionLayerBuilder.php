<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

use App\Services\AI\FilmOS\ExecutionGraph\CheckpointEntry;
use App\Services\AI\FilmOS\ExecutionGraph\ExecutionGraph;
use App\Services\AI\FilmOS\ExecutionGraph\ExecutionNodeStatus;
use App\Services\AI\FilmOS\ExecutionGraph\ExecutionRuntimeState;

/**
 * Builds Phase B snapshot hashes.
 *
 * Data sources:
 *   ExecutionGraph          — topology only (nodes + edges); used for executionTopologyHash
 *   ExecutionRuntimeState[] — all mutable runtime state; used for all three hashes
 *   CheckpointEntry[]       — ordered checkpoint log emitted by ExecutionRuntime
 *
 * This builder NEVER reads ExecutionNode mutable fields (status, retryCount, completedAt).
 * All runtime data comes from ExecutionRuntimeState. The boundary is enforced by
 * the parameter types — there is no path to ExecutionNode mutable state from here.
 *
 * Three hash contracts (ADR-016 Phase B):
 *
 *   executionTopologyHash
 *     topology: (taskId, status.value) per node; (from, to, rel) per edge.
 *     status comes from ExecutionRuntimeState — NOT ExecutionNode.
 *     Two runs with same topology + same terminal statuses MUST match.
 *
 *   checkpointHash
 *     Directly hashes CheckpointEntry[]: (taskId, status, ordinal).
 *     Ordinals are emitted by the runtime in event order — no timestamp derivation.
 *     A COMPLETED and a FAILED checkpoint for the same task produce different hashes.
 *
 *   retrySequenceHash
 *     Hashes ExecutionRuntimeState::retryHistory[] per task.
 *     retryHistory = direct outcome log: ['failed', 'failed', 'completed'].
 *     NOT reconstructed from retryCount + status.
 *     Sorted by taskId for determinism.
 */
final class ExecutionLayerBuilder
{
    public function __construct(
        private readonly HashSerializer $serializer = new JsonHashSerializer(),
    ) {}

    /**
     * @param  array<string, ExecutionRuntimeState>  $states        taskId → state
     * @param  CheckpointEntry[]                     $checkpointLog in emission order
     */
    public function build(
        ExecutionGraph $graph,
        array          $states,
        array          $checkpointLog,
    ): ExecutionSection {
        return new ExecutionSection(
            executionTopologyHash: $this->buildExecutionTopologyHash($graph, $states),
            checkpointHash:        $this->buildCheckpointHash($checkpointLog),
            retrySequenceHash:     $this->buildRetrySequenceHash($states),
        );
    }

    // ── Private builders ──────────────────────────────────────────────────────

    /**
     * Topology hash: (taskId, status.value) per node + (from, to, rel) per edge.
     * Status is read from ExecutionRuntimeState — never from ExecutionNode.
     * Renamed from buildExecutionGraphHash in Phase D (more accurate: hashes topology + runtime status).
     */
    private function buildExecutionTopologyHash(ExecutionGraph $graph, array $states): string
    {
        $nodes = [];
        foreach ($graph->nodes() as $node) {
            $state = $states[$node->taskId] ?? null;
            $nodes[$node->taskId] = [
                'taskId' => $node->taskId,
                'status' => $state?->status->value ?? ExecutionNodeStatus::PENDING->value,
            ];
        }
        ksort($nodes);

        $edges = [];
        foreach ($graph->edges() as $edge) {
            $edges[] = $this->serializer->serialize(
                $edge instanceof HashableEdge
                    ? $edge->canonicalEdge()->toArray()
                    : ['from' => $edge->fromId, 'to' => $edge->toId],
            );
        }
        sort($edges);

        return $this->serializer->sha256(['nodes' => $nodes, 'edges' => $edges]);
    }

    /**
     * Checkpoint hash: directly from the ordered CheckpointEntry log.
     * No timestamps. No completedAt derivation. Pure structure + sequence.
     */
    private function buildCheckpointHash(array $checkpointLog): string
    {
        $canonical = array_map(
            static fn(CheckpointEntry $e) => [
                'taskId'  => $e->taskId,
                'status'  => $e->status,
                'ordinal' => $e->ordinal,
            ],
            $checkpointLog,
        );

        return $this->serializer->sha256($canonical);
    }

    /**
     * Retry-sequence hash: (taskId, retryHistory[]) per task, sorted by taskId.
     * retryHistory is the direct outcome log — NOT reconstructed.
     * Example: ['failed', 'failed', 'completed'] for 2 retries then success.
     *
     * @param  array<string, ExecutionRuntimeState>  $states
     */
    private function buildRetrySequenceHash(array $states): string
    {
        // Sort by taskId for deterministic ordering regardless of execution order
        ksort($states);

        $canonical = array_map(
            static fn(string $taskId, ExecutionRuntimeState $state) => [
                'taskId'       => $taskId,
                'retryHistory' => $state->retryHistory,
            ],
            array_keys($states),
            array_values($states),
        );

        return $this->serializer->sha256($canonical);
    }
}
