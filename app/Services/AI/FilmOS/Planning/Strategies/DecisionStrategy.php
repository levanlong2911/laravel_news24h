<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning\Strategies;

use App\Services\AI\FilmOS\Planning\GoalNode;
use App\Services\AI\FilmOS\Meaning\MeaningGraph;

interface DecisionStrategy
{
    public function name(): string;

    /** @return DecisionCandidate[] */
    public function candidates(GoalNode $subGoal, MeaningGraph $meaning, array $worldState): array;
}
