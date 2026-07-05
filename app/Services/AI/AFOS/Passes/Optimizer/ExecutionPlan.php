<?php

namespace App\Services\AI\AFOS\Passes\Optimizer;

use App\Services\AI\AFOS\Passes\Pipeline\CompilerStage;
use App\Services\AI\AFOS\Passes\Pipeline\StageCost;

/**
 * ExecutionPlan — optimized DAG produced by PassOptimizer.
 *
 * The bridge between PassOptimizer (what to run) and the Scheduler (how to
 * run it). Contains levels for parallel grouping + metadata for observability.
 *
 * Usage:
 *   $plan = PassOptimizer::defaults()->optimize($def, $context);
 *
 *   // Sequential execution (current default):
 *   foreach ($plan->flatStages() as $stage) { ... }
 *
 *   // Parallel execution (future ParallelScheduler):
 *   foreach ($plan->levels as $level) { runParallel($level->stages); }
 */
final class ExecutionPlan
{
    /**
     * @param ExecutionLevel[] $levels       Topological levels (level 0 runs first)
     * @param string[]         $skippedStages Stage names removed by optimization passes
     * @param StageCost        $estimatedCost Aggregate cost after elimination
     * @param string[]         $appliedPasses Names of passes that ran
     */
    public function __construct(
        public readonly array     $levels,
        public readonly array     $skippedStages,
        public readonly StageCost $estimatedCost,
        public readonly array     $appliedPasses,
    ) {}

    // ── Stage access ──────────────────────────────────────────────────────────

    /**
     * Flat ordered list of stages for sequential execution.
     * Order: level 0 first, within each level in the order PassOptimizer placed them.
     *
     * @return CompilerStage[]
     */
    public function flatStages(): array
    {
        if (empty($this->levels)) {
            return [];
        }
        return array_merge(...array_map(fn(ExecutionLevel $l) => $l->stages, $this->levels));
    }

    // ── Metrics ───────────────────────────────────────────────────────────────

    public function levelCount(): int
    {
        return count($this->levels);
    }

    public function stageCount(): int
    {
        return count($this->flatStages());
    }

    /** Estimated latency if each level runs in parallel (ideal case). */
    public function estimatedParallelMs(): float
    {
        return (float) array_sum(array_map(
            fn(ExecutionLevel $l) => $l->estimatedParallelMs(),
            $this->levels
        ));
    }

    /** Estimated latency if all stages run sequentially (= estimatedCost->estimatedMs). */
    public function estimatedSequentialMs(): float
    {
        return $this->estimatedCost->estimatedMs;
    }

    /** Estimated speedup from parallelism: sequential / parallel. */
    public function parallelSpeedup(): float
    {
        $parallel = $this->estimatedParallelMs();
        if ($parallel <= 0.0) {
            return 1.0;
        }
        return round($this->estimatedSequentialMs() / $parallel, 3);
    }

    // ── Serialization ─────────────────────────────────────────────────────────

    public function describe(): array
    {
        return [
            'levels'                   => array_map(fn(ExecutionLevel $l) => $l->toArray(), $this->levels),
            'skipped_stages'           => $this->skippedStages,
            'applied_passes'           => $this->appliedPasses,
            'estimated_cost'           => $this->estimatedCost->toArray(),
            'estimated_parallel_ms'    => $this->estimatedParallelMs(),
            'estimated_sequential_ms'  => $this->estimatedSequentialMs(),
            'parallel_speedup'         => $this->parallelSpeedup(),
        ];
    }
}
