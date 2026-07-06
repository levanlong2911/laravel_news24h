<?php

namespace App\Services\AI\AFOS\Passes\Scheduler;

use App\Services\AI\AFOS\Passes\Optimizer\ExecutionPlan;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineState;

/**
 * SequentialScheduler — runs every stage in flatStages() order, one at a time.
 *
 * Safe for all stages regardless of capability flags. Produces identical output
 * to ParallelScheduler because stages within a level have disjoint writes.
 * Useful as the default and for debugging (deterministic execution trace).
 */
final class SequentialScheduler implements Scheduler
{
    public function execute(ExecutionPlan $plan, PipelineState $state): PipelineState
    {
        foreach ($plan->flatStages() as $stage) {
            $state = $stage->run($state);
        }
        return $state;
    }

    public function name(): string
    {
        return 'sequential';
    }
}
