<?php

namespace App\Services\AI\AFOS\Passes\Optimizer\Passes;

use App\Services\AI\AFOS\Passes\Optimizer\DependencyLevelBuilder;
use App\Services\AI\AFOS\Passes\Optimizer\OptimizationContext;
use App\Services\AI\AFOS\Passes\Optimizer\OptimizationPass;
use App\Services\AI\AFOS\Passes\Pipeline\CompilerStage;

/**
 * CostAwareOrderingPass — sorts stages within each topological level by estimated cost.
 *
 * Stages at the same level are data-independent: they can safely be reordered.
 * This pass sorts them cheapest-first (ascending estimatedMs) so that:
 *   - Cheap validation stages run before expensive transforms → fail fast
 *   - When a ParallelScheduler runs a level, the order is already optimal
 *     (cheaper stages finish sooner, unblocking downstream levels faster)
 *
 * Example — standard pipeline, Level 0:
 *   Before: [ShotValidation(0.5ms), Tier1(8ms)]   (declaration order)
 *   After:  [ShotValidation(0.5ms), Tier1(8ms)]   (already cheapest first — no change)
 *
 * Example — standard pipeline, Level 2:
 *   Before: [CameraValidation(0.3ms), Tier3(12ms)] (declaration order)
 *   After:  [CameraValidation(0.3ms), Tier3(12ms)] (already cheapest first — no change)
 *
 * If a future pipeline reverses the order:
 *   Before: [Tier3(12ms), CameraValidation(0.3ms)]
 *   After:  [CameraValidation(0.3ms), Tier3(12ms)] (corrected)
 *
 * Level computation uses the same DP as PassOptimizer — avoids recomputing from a graph.
 */
final class CostAwareOrderingPass implements OptimizationPass
{
    public function optimize(array $stages, OptimizationContext $context): array
    {
        if (count($stages) <= 1) {
            return $stages;
        }

        $groups = DependencyLevelBuilder::groupByLevel($stages);

        foreach ($groups as &$group) {
            usort(
                $group,
                fn(CompilerStage $a, CompilerStage $b) =>
                    $a->metadata()->cost->estimatedMs <=> $b->metadata()->cost->estimatedMs
            );
        }
        unset($group);

        return array_merge(...array_values($groups));
    }

    public function name(): string
    {
        return 'CostAwareOrdering';
    }

    public function description(): string
    {
        return 'Within each topological level, sorts stages by estimated cost ascending '
             . '(cheapest first) for fail-fast behavior and optimal parallel execution.';
    }
}
