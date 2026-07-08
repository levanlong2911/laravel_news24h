<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning;

use App\Services\AI\FilmOS\Meaning\MeaningGraph;

interface FilmPlanner
{
    public function plan(
        GoalGraph      $goals,
        MeaningGraph   $meaning,
        array          $worldState,
        PlanObjectives $objectives,
    ): ShotSequencePlan;
}
