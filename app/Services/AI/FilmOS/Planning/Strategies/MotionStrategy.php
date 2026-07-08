<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning\Strategies;

use App\Services\AI\FilmOS\Meaning\CinematicFunction;
use App\Services\AI\FilmOS\Meaning\MeaningGraph;
use App\Services\AI\FilmOS\Planning\GoalNode;

final class MotionStrategy implements DecisionStrategy
{
    public function name(): string
    {
        return 'motion';
    }

    public function candidates(GoalNode $subGoal, MeaningGraph $meaning, array $worldState): array
    {
        $fn  = $meaning->cinematicFunction;
        $dof = match ($fn) {
            CinematicFunction::REVEAL => 'SHALLOW',
            default                   => 'MEDIUM',
        };

        return [
            new DecisionCandidate(
                strategyName: 'motion',
                execution:    ['dof' => $dof],
                score:     0.80,
                rationale: "DOF {$dof} for {$fn->value}",
            ),
        ];
    }
}
