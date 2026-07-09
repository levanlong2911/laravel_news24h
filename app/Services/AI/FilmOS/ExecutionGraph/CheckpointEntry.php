<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\ExecutionGraph;

/**
 * A single checkpoint event emitted by ExecutionRuntime.
 *
 * ExecutionRuntime calls saveCheckpoint() after every node transition
 * (COMPLETED, FAILED, or SKIPPED). Each call produces one CheckpointEntry.
 *
 * Hash contract (ADR-016 Phase B):
 *   taskId  — planning-level identity (stable across replays)
 *   status  — terminal status of the task at checkpoint time
 *   ordinal — sequential position within THIS run (1, 2, 3…)
 *
 * Excluded: wall-clock timestamps, payload, graph size, checkpoint byte count.
 * Two identical execution runs MUST produce the same CheckpointEntry sequence.
 */
final class CheckpointEntry
{
    public function __construct(
        public readonly string $taskId,  // from ExecutionNode::taskId
        public readonly string $status,  // 'completed' | 'failed' | 'skipped'
        public readonly int    $ordinal, // 1-based; reset per run
    ) {}
}
