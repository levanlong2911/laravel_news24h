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
 * Excluded from checkpointHash: timestamps, eventId, parentEventId, payload.
 * Two structurally identical runs MUST produce the same CheckpointEntry sequence.
 *
 * Phase D additions (ADR-016 Phase D):
 *   eventId       — UUID v4; unique identity for this checkpoint event.
 *                   Enables targeting a specific rollback point, creating forks,
 *                   or detecting diverged branches across replay runs.
 *   parentEventId — eventId of the immediately preceding checkpoint in this run.
 *                   null for the first checkpoint; forms a linked chain.
 *
 * eventId + parentEventId are NOT included in checkpointHash — they are
 * run-instance identifiers, not semantic state. Including them would make
 * two structurally identical runs hash differently.
 */
final class CheckpointEntry
{
    public function __construct(
        public readonly string  $taskId,        // from ExecutionNode::taskId
        public readonly string  $status,        // 'completed' | 'failed' | 'skipped'
        public readonly int     $ordinal,       // 1-based; reset per run
        public readonly string  $eventId,       // UUID v4 — for rollback / fork / merge
        public readonly ?string $parentEventId, // preceding checkpoint's eventId; null if first
    ) {}
}
