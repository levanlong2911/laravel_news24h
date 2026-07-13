<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning;

use App\Services\AI\FilmOS\Meaning\MeaningGraph;

final class PlanningLegacyContext
{
    public function __construct(
        public readonly GoalNode     $goalNode,
        public readonly MeaningGraph $meaning,
        public readonly array        $worldState = [],
    ) {}
}
