<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

use App\Services\AI\FilmOS\DecisionDAG\DecisionDAG;
use App\Services\AI\FilmOS\Intent\DirectorIntent;
use App\Services\AI\FilmOS\Planning\GoalGraph;
use App\Services\AI\FilmOS\Planning\ShotSequencePlan;

/**
 * Backward-compatible facade over SnapshotComposer.
 *
 * New callers should use SnapshotComposer::composeFromPlan() directly
 * so they can pass a proper DeterminismManifest with real version strings.
 *
 * This facade calls DeterminismManifest::current() with a worldVersion
 * derived from the DAG's production ID — sufficient for golden scenario runs.
 */
final class ExecutionSnapshotBuilder
{
    private readonly SnapshotComposer $composer;

    public function __construct()
    {
        $this->composer = new SnapshotComposer(new PlanningSnapshotBuilder());
    }

    /**
     * @param  array<string, DirectorIntent>  $intents      subGoalId → DirectorIntent
     * @param  TaskDescriptor[]               $descriptors  empty = no schedulerHash
     */
    public function build(
        string           $productionId,
        DecisionDAG      $dag,
        GoalGraph        $goalGraph,
        ShotSequencePlan $plan,
        array            $intents,
        array            $descriptors = [],
    ): ExecutionSnapshot {
        $manifest = DeterminismManifest::current(
            worldVersion: hash('sha256', $productionId),
        );

        return $this->composer->composeFromPlan(
            $productionId,
            $manifest,
            $dag,
            $goalGraph,
            $plan,
            $intents,
            $descriptors,
        );
    }
}
