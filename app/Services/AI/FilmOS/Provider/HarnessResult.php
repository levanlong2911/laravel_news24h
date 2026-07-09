<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Provider;

use App\Services\AI\FilmOS\ExecutionGraph\CheckpointEntry;
use App\Services\AI\FilmOS\ExecutionGraph\ExecutionGraph;
use App\Services\AI\FilmOS\ExecutionGraph\ExecutionMetrics;
use App\Services\AI\FilmOS\ExecutionGraph\ExecutionRuntimeState;
use App\Services\AI\FilmOS\Snapshot\ExecutionSection;

/**
 * Result from ProviderHarness::run().
 *
 * Contains the populated ExecutionGraph (topology), the already-computed
 * Phase B ExecutionSection, and the raw runtime data for introspection/testing:
 *   states        — Map<taskId, ExecutionRuntimeState>; source of truth for hashing
 *   checkpointLog — ordered CheckpointEntry[] emitted during the run
 */
final class HarnessResult
{
    public function __construct(
        public readonly ExecutionGraph   $graph,
        public readonly ExecutionSection $executionSection,
        public readonly ExecutionMetrics $metrics,

        /** @var array<string, ExecutionRuntimeState>  taskId → runtime state */
        public readonly array            $states        = [],

        /** @var CheckpointEntry[]  in emission order */
        public readonly array            $checkpointLog = [],
    ) {}

    public function isFullyCompleted(): bool
    {
        return $this->graph->isFullyCompleted() && !$this->graph->hasFailures();
    }
}
