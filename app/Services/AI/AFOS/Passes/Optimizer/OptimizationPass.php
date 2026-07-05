<?php

namespace App\Services\AI\AFOS\Passes\Optimizer;

use App\Services\AI\AFOS\Passes\Pipeline\CompilerStage;

/**
 * OptimizationPass — one atomic pipeline transformation applied by PassOptimizer.
 *
 * Each pass receives the current ordered stage list, applies one specific
 * transformation, and returns a new list. Passes must be pure:
 *   - No side effects outside the returned array
 *   - Same input → same output (deterministic)
 *   - Must return a subset or reorder of the input stages (no new stages)
 *
 * Passes run sequentially in PassOptimizer::optimize(); the output of each
 * becomes the input of the next.
 */
interface OptimizationPass
{
    /**
     * Transform the stage list according to this pass's optimization strategy.
     *
     * @param  CompilerStage[] $stages  Current pipeline stages (in topological order)
     * @return CompilerStage[]          Optimized stage list (subset and/or reordered)
     */
    public function optimize(array $stages, OptimizationContext $context): array;

    /** Short name for observability (shown in ExecutionPlan::appliedPasses). */
    public function name(): string;

    /** One-line description of what this pass does. */
    public function description(): string;
}
