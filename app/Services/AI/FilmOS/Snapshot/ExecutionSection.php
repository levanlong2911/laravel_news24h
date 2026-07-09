<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

/**
 * Phase B snapshot section — ExecutionGraph layer.
 *
 * Hash contracts (ADR-016 Phase B):
 *   executionGraphHash — node id + status.value; NOT elapsedMs, startedAt
 *   checkpointHash     — ordered (nodeId, completedAt-ordinal); NOT wall-clock timestamps
 *   retrySequenceHash  — ordered (nodeId, retryCount) by nodeId; NOT timestamps
 */
final class ExecutionSection implements SnapshotSection
{
    public function __construct(
        public readonly string $executionGraphHash,
        public readonly string $checkpointHash,
        public readonly string $retrySequenceHash,
    ) {}

    public function fields(): array
    {
        return [
            'executionGraphHash' => $this->executionGraphHash,
            'checkpointHash'     => $this->checkpointHash,
            'retrySequenceHash'  => $this->retrySequenceHash,
        ];
    }
}
