<?php

namespace App\Services\AI\AFOS\Passes\Optimizer;

use App\Services\AI\AFOS\Passes\Optimizer\Passes\CostAwareOrderingPass;
use App\Services\AI\AFOS\Passes\Optimizer\Passes\DeadStageEliminationPass;
use App\Services\AI\AFOS\Passes\Pipeline\CompilerStage;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineDefinition;
use App\Services\AI\AFOS\Passes\Pipeline\StageCost;

/**
 * PassOptimizer — applies a sequence of OptimizationPass transformations to a pipeline.
 *
 * Produces an ExecutionPlan with:
 *   - A topologically-leveled DAG (stages grouped for parallel execution)
 *   - Skipped stage names (removed by passes)
 *   - Aggregate cost estimate after elimination
 *
 * Pass execution order matters: each pass receives the output of the previous one.
 * Standard order: DeadStageElimination → CostAwareOrdering
 *
 * Usage:
 *   $plan = PassOptimizer::defaults()->optimize($def, OptimizationContext::full());
 *   $plan = PassOptimizer::defaults()->optimize($def, OptimizationContext::draft());
 *
 *   // Custom pass pipeline:
 *   $opt = PassOptimizer::defaults()->withPass(new MyCustomPass());
 *
 * Immutable: withPass() returns a new optimizer.
 */
final class PassOptimizer
{
    /** @param OptimizationPass[] $passes */
    public function __construct(private readonly array $passes = []) {}

    /** Standard optimizer with all built-in passes. */
    public static function defaults(): self
    {
        return new self([
            new DeadStageEliminationPass(),
            new CostAwareOrderingPass(),
        ]);
    }

    /** Returns a new optimizer with the given pass appended. Immutable. */
    public function withPass(OptimizationPass $pass): self
    {
        return new self([...$this->passes, $pass]);
    }

    // ── Core optimization ─────────────────────────────────────────────────────

    /**
     * Apply all registered passes to the pipeline, then compute execution levels.
     *
     * @return ExecutionPlan with topological levels + observability metadata
     */
    public function optimize(PipelineDefinition $def, OptimizationContext $context): ExecutionPlan
    {
        $stages        = $def->stages();
        $skipped       = [];
        $appliedPasses = [];

        foreach ($this->passes as $pass) {
            $before  = array_map(fn(CompilerStage $s) => $s->name(), $stages);
            $stages  = $pass->optimize($stages, $context);
            $after   = array_map(fn(CompilerStage $s) => $s->name(), $stages);
            $removed = array_values(array_diff($before, $after));

            $skipped        = array_merge($skipped, $removed);
            $appliedPasses[] = $pass->name();
        }

        $estimatedCost = array_reduce(
            $stages,
            fn(StageCost $c, CompilerStage $s) => $c->add($s->metadata()->cost),
            StageCost::free()
        );

        $levels = $this->computeLevels($stages);

        return new ExecutionPlan($levels, $skipped, $estimatedCost, $appliedPasses);
    }

    // ── Level computation ─────────────────────────────────────────────────────

    /**
     * Assign each stage to a topological level via DP.
     *
     * level[i] = max(level[dep] + 1) for all stages dep that produce something
     * stage i reads. Stages with no IR dependencies land on level 0.
     *
     * @param  CompilerStage[] $stages Must be in valid topological order.
     * @return ExecutionLevel[]
     */
    private function computeLevels(array $stages): array
    {
        if (empty($stages)) {
            return [];
        }

        // producedBy[fqcn] = index into $stages
        $producedBy = [];
        foreach ($stages as $i => $stage) {
            foreach ($stage->metadata()->writes as $write) {
                $producedBy[$write] = $i;
            }
        }

        // DP: stages must be in topological order for this to be correct
        $stageLevels = array_fill(0, count($stages), 0);
        foreach ($stages as $i => $stage) {
            foreach ($stage->metadata()->reads as $read) {
                if (isset($producedBy[$read])) {
                    $depLevel        = $stageLevels[$producedBy[$read]];
                    $stageLevels[$i] = max($stageLevels[$i], $depLevel + 1);
                }
            }
        }

        // Group by level, preserving per-level order from the pass pipeline
        $groups = [];
        foreach ($stages as $i => $stage) {
            $groups[$stageLevels[$i]][] = $stage;
        }
        ksort($groups);

        $levels = [];
        foreach ($groups as $idx => $stagesAtLevel) {
            $levels[] = new ExecutionLevel($idx, $stagesAtLevel);
        }

        return $levels;
    }
}
