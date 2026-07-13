<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

/**
 * Phase B snapshot section — ExecutionGraph layer.
 *
 * Hash contracts (ADR-016 Phase B / Phase D):
 *   executionTopologyHash — (taskId, status.value) per node + (from, to, rel) per edge.
 *                           Renamed from executionGraphHash in Phase D: the hash covers
 *                           topology AND runtime status, not the graph object itself.
 *   checkpointHash        — ordered (taskId, status, ordinal); NOT timestamps or eventIds
 *   retrySequenceHash     — ordered (taskId, retryHistory[]) by taskId; NOT reconstructed
 */
final class ExecutionSection implements SnapshotSection
{
    public function __construct(
        public readonly string $executionTopologyHash,
        public readonly string $checkpointHash,
        public readonly string $retrySequenceHash,
    ) {}

    public static function name(): string { return 'execution'; }

    public static function requiredFields(): array
    {
        return ['executionTopologyHash', 'checkpointHash', 'retrySequenceHash'];
    }

    public static function optionalFields(): array { return []; }

    public function fields(): array
    {
        return [
            'executionTopologyHash' => $this->executionTopologyHash,
            'checkpointHash'        => $this->checkpointHash,
            'retrySequenceHash'     => $this->retrySequenceHash,
        ];
    }
}
