<?php

namespace App\Services\AI\AFOS\Passes\Optimizer;

use App\Services\AI\AFOS\Passes\Pipeline\CompilerStage;

/**
 * ExecutionLevel — one row in the optimized execution DAG.
 *
 * All stages in a level are data-independent: they can run in parallel
 * without race conditions (they don't read each other's outputs).
 *
 * Level 0 → Level 1 → Level 2 → ...  (sequential between levels)
 * Within a level                      (parallel within a level)
 *
 * The standard Kling pipeline produces 4 levels:
 *   Level 0: [ShotValidation, Tier1]          — both read only pipeline inputs
 *   Level 1: [Tier2]                           — reads CompositionIR from Tier1
 *   Level 2: [CameraValidation, Tier3]         — both read CameraIR from Tier2
 *   Level 3: [BackendStage]                    — reads PromptIR from Tier3
 */
final class ExecutionLevel
{
    /** @param CompilerStage[] $stages */
    public function __construct(
        public readonly int   $index,
        public readonly array $stages,
    ) {}

    /** @return string[] */
    public function stageNames(): array
    {
        return array_map(fn(CompilerStage $s) => $s->name(), $this->stages);
    }

    /**
     * Estimated latency for this level when stages run in parallel.
     * = max(estimatedMs across all stages)
     */
    public function estimatedParallelMs(): float
    {
        if (empty($this->stages)) {
            return 0.0;
        }
        return (float) max(array_map(
            fn(CompilerStage $s) => $s->metadata()->cost->estimatedMs,
            $this->stages
        ));
    }

    /**
     * Estimated latency for this level when stages run sequentially.
     * = sum(estimatedMs across all stages)
     */
    public function estimatedSequentialMs(): float
    {
        return (float) array_sum(array_map(
            fn(CompilerStage $s) => $s->metadata()->cost->estimatedMs,
            $this->stages
        ));
    }

    /** Number of stages in this level. */
    public function count(): int
    {
        return count($this->stages);
    }

    public function toArray(): array
    {
        return [
            'index'                  => $this->index,
            'stages'                 => $this->stageNames(),
            'estimated_parallel_ms'  => $this->estimatedParallelMs(),
            'estimated_sequential_ms' => $this->estimatedSequentialMs(),
        ];
    }
}
