<?php

namespace App\Services\AI\AFOS\Passes\Scheduler;

use App\Services\AI\AFOS\Passes\Optimizer\ExecutionPlan;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineState;

/**
 * Scheduler — executes an ExecutionPlan against a PipelineState.
 *
 * The optimizer produces an ExecutionPlan (with topological levels).
 * The scheduler decides HOW to run it: sequentially or in parallel.
 *
 * Implementations:
 *   SequentialScheduler — runs flatStages() one-by-one (safe for all stages)
 *   ParallelScheduler   — runs stages within each level concurrently via Fibers
 *                         (stages must not share mutable state within a level)
 */
interface Scheduler
{
    /**
     * Execute the plan and return the final pipeline state.
     *
     * The input $state carries all pipeline inputs (ShotGoalIR, DirectorProfile,
     * CinematographyProfile, Intent). The returned state carries all IR artifacts
     * produced by the plan's stages.
     */
    public function execute(ExecutionPlan $plan, PipelineState $state): PipelineState;

    /** Short identifier for observability (e.g. 'sequential', 'parallel'). */
    public function name(): string;
}
