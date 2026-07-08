<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning\Strategies;

use App\Services\AI\FilmOS\Meaning\CinematicFunction;
use App\Services\AI\FilmOS\Meaning\MeaningGraph;
use App\Services\AI\FilmOS\Planning\GoalNode;
use App\Services\AI\FilmOS\Planning\VisualStrategy;

final class CameraStrategy implements DecisionStrategy
{
    public function name(): string
    {
        return 'camera';
    }

    public function candidates(GoalNode $subGoal, MeaningGraph $meaning, array $worldState): array
    {
        $fn = $meaning->cinematicFunction;

        $lens      = match ($fn) {
            CinematicFunction::REVEAL    => 85,
            CinematicFunction::ESCALATE  => 35,
            CinematicFunction::ESTABLISH => 50,
            default                      => 50,
        };

        $stability = match ($fn) {
            CinematicFunction::ESCALATE => 'HANDHELD_SUBTLE',
            default                     => 'LOCKED',
        };

        $movement = match ($fn) {
            CinematicFunction::REVEAL => 'SLOW_PUSH',
            default                   => 'STATIC',
        };

        $visualStrategy = match ($fn) {
            CinematicFunction::ESCALATE => VisualStrategy::URGENT,
            default                     => VisualStrategy::OBSERVATIONAL,
        };

        return [
            new DecisionCandidate(
                strategyName: 'camera',
                execution:    [
                    'lens'           => $lens,
                    'stability'      => $stability,
                    'movement'       => $movement,
                    'visualStrategy' => $visualStrategy,
                ],
                score:     0.85,
                rationale: "Lens {$lens}mm, {$stability}, {$movement} for {$fn->value}",
            ),
        ];
    }
}
