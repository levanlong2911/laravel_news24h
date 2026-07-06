<?php

namespace App\Services\AI\AFOS\Passes\Scheduler;

use App\Services\AI\AFOS\Passes\Optimizer\ExecutionLevel;
use App\Services\AI\AFOS\Passes\Optimizer\ExecutionPlan;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineState;

/**
 * ParallelScheduler — runs stages within each ExecutionLevel concurrently via PHP Fibers.
 *
 * Correctness guarantee (from the level invariant):
 *   Stages within the same level have DISJOINT writes. They read only from the
 *   input state (produced by the previous level) and never from each other's output.
 *   PipelineState is fully immutable — all stages safely share the same input reference.
 *
 * Concurrency model:
 *   Each stage in a level runs inside a \Fiber. PHP Fibers are cooperative: without
 *   explicit Fiber::suspend() calls, each fiber runs to completion before the next
 *   one starts (sequentially). When stages gain async IO and call Fiber::suspend(),
 *   the drive loop in executeLevel() cooperates between them, achieving true overlap.
 *
 * DiagnosticBag:
 *   The bag is the one shared mutable reference on PipelineState. Stages mutate it
 *   via $state->bag->error() / warn() / hint(). Since PHP is single-threaded and
 *   Fibers are cooperative, there is no concurrent mutation — no locking required.
 *   Diagnostics accumulate correctly across all stages in a level.
 *
 * Merge:
 *   After all fibers in a level complete, mergeLevel() folds their written fields
 *   (composition, camera, promptIR, compiledPrompt) onto the merged state.
 *   Fields not written by any stage in this level keep their value from the input.
 */
final class ParallelScheduler implements Scheduler
{
    public function execute(ExecutionPlan $plan, PipelineState $state): PipelineState
    {
        foreach ($plan->levels as $level) {
            $state = $this->executeLevel($level, $state);
        }
        return $state;
    }

    private function executeLevel(ExecutionLevel $level, PipelineState $state): PipelineState
    {
        // Single-stage levels: skip Fiber overhead — just run directly.
        if ($level->count() === 1) {
            return $level->stages[0]->run($state);
        }

        // Start one Fiber per stage. PipelineState is immutable — all fibers share
        // the same $state reference safely. The Fiber closure captures $stage and
        // $state by value (PHP closures capture object references, which is correct here
        // since we want each fiber to work from the SAME input state).
        $fibers = [];
        foreach ($level->stages as $stage) {
            $fiber = new \Fiber(static fn(): PipelineState => $stage->run($state));
            $fiber->start();
            $fibers[] = $fiber;
        }

        // Cooperative drive loop: resume any suspended fiber until all terminate.
        // For currently-synchronous stages this loop exits immediately (no suspensions).
        // For future async stages (IO_BOUND, MODEL_INFERENCE), fibers will suspend here
        // and the loop provides the interleaving.
        do {
            $anyPending = false;
            foreach ($fibers as $fiber) {
                if ($fiber->isSuspended()) {
                    $fiber->resume();
                    $anyPending = true;
                }
            }
        } while ($anyPending);

        // Merge: fold each fiber's output onto the accumulated state.
        // DiagnosticBag mutations are already visible (shared reference).
        $merged = $state;
        foreach ($fibers as $fiber) {
            $merged = $this->mergeOutput($state, $merged, $fiber->getReturn());
        }

        return $merged;
    }

    /**
     * Carry fields written by one stage's output onto the accumulated merged state.
     *
     * Uses $input (the level's starting state) as the baseline for change detection:
     * if a field in $output differs from $input, that stage wrote it and we apply it.
     *
     * The level invariant guarantees stages write disjoint fields, so no output
     * will overwrite another stage's write in the same level.
     */
    private function mergeOutput(
        PipelineState $input,
        PipelineState $merged,
        PipelineState $output,
    ): PipelineState {
        if ($output->composition !== $input->composition) {
            $merged = $merged->withComposition($output->composition);
        }
        if ($output->camera !== $input->camera) {
            $merged = $merged->withCamera($output->camera);
        }
        if ($output->promptIR !== $input->promptIR) {
            $merged = $merged->withPromptIR($output->promptIR);
        }
        if ($output->compiledPrompt !== $input->compiledPrompt) {
            $merged = $merged->withCompiledPrompt($output->compiledPrompt);
        }
        if ($output->graph !== $input->graph) {
            $merged = $merged->withGraph($output->graph);
        }
        if ($output->frozenGraph !== $input->frozenGraph) {
            $merged = $merged->withFrozenGraph($output->frozenGraph);
        }
        if ($output->phase !== $input->phase) {
            $merged = $merged->withPhase($output->phase);
        }
        // DiagnosticBag: shared mutable reference — mutations already on $merged->bag.
        return $merged;
    }

    public function name(): string
    {
        return 'parallel';
    }
}
