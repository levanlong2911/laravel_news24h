<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

use App\Services\AI\FilmOS\DecisionDAG\DecisionDAG;
use App\Services\AI\FilmOS\Intent\DirectorIntent;
use App\Services\AI\FilmOS\Kernel\FilmTask;
use App\Services\AI\FilmOS\Planning\GoalGraph;
use App\Services\AI\FilmOS\Planning\ShotSequencePlan;

/**
 * Backward-compatible facade over SnapshotComposer.
 *
 * New callers should inject and use SnapshotComposer directly.
 * This class exists so commands written before the composer split
 * continue to compile without change.
 *
 * API change from Phase A initial:
 *   Before: build(..., array $intentPrompts, ?array $taskOrder)  string[]
 *   After:  build(..., array $intents, array $tasks)             DirectorIntent[] / FilmTask[]
 */
final class ExecutionSnapshotBuilder
{
    private readonly SnapshotComposer $composer;

    public function __construct()
    {
        $this->composer = new SnapshotComposer(new PlanningSnapshotBuilder());
    }

    /**
     * @param  array<string, DirectorIntent>  $intents  subGoalId → DirectorIntent
     * @param  FilmTask[]                     $tasks    Kernel tasks (empty = no schedulerHash)
     */
    public function build(
        string $productionId,
        DecisionDAG $dag,
        GoalGraph $goalGraph,
        ShotSequencePlan $plan,
        array $intents,
        array $tasks = [],
    ): ExecutionSnapshot {
        return $this->composer->compose($productionId, $dag, $goalGraph, $plan, $intents, $tasks);
    }
}
