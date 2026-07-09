<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\ExecutionGraph;

/**
 * Result of one ExecutionRuntime::run() call.
 *
 * Immutable after creation.
 *
 * Phase B additions:
 *   states        — Map<taskId, ExecutionRuntimeState>; canonical mutable state
 *                   for ExecutionLayerBuilder. Never read ExecutionNode fields for hashing.
 *   checkpointLog — ordered CheckpointEntry[] emitted by the runtime; used by
 *                   ExecutionLayerBuilder::buildCheckpointHash() without touching timestamps.
 */
final class ExecutionResult
{
    public function __construct(
        public readonly ExecutionGraph   $graph,
        public readonly float            $totalElapsedMs,

        /** @var string[] node IDs actually executed in this run */
        public readonly array            $executedNodeIds,

        /** @var string[] node IDs skipped (already COMPLETED or dep FAILED) */
        public readonly array            $skippedNodeIds,

        public readonly bool             $resumedFromCheckpoint,
        public readonly ExecutionMetrics $metrics = new ExecutionMetrics(),

        /** @var array<string, ExecutionRuntimeState>  taskId → state */
        public readonly array            $states        = [],

        /** @var CheckpointEntry[]  one entry per saveCheckpoint() call, in emission order */
        public readonly array            $checkpointLog = [],
    ) {}

    public function isFullyCompleted(): bool
    {
        return $this->graph->isFullyCompleted() && !$this->graph->hasFailures();
    }

    public function hasFailures(): bool
    {
        return $this->graph->hasFailures();
    }

    /** @return ExecutionNode[] */
    public function failedNodes(): array
    {
        return $this->graph->failedNodes();
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return array_merge($this->graph->summary(), [
            'totalElapsedMs'        => $this->totalElapsedMs,
            'executedCount'         => count($this->executedNodeIds),
            'skippedFromCheckpoint' => count($this->skippedNodeIds),
            'resumedFromCheckpoint' => $this->resumedFromCheckpoint,
            'checkpointEntries'     => count($this->checkpointLog),
            'metrics'               => $this->metrics->toArray(),
        ]);
    }
}
