<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Meaning;

use App\Services\AI\FilmOS\Graph\Graph;

/**
 * @extends Graph<MeaningNode, MeaningEdge>
 */
final class MeaningGraph extends Graph
{
    public function __construct(
        public readonly string            $rootNodeId,
        public readonly CinematicFunction $cinematicFunction,
        public readonly float             $tensionLevel,
        public readonly float             $confidence,
    ) {}

    public function root(): MeaningNode
    {
        return $this->node($this->rootNodeId);
    }

    public function hasAmbiguity(): bool
    {
        foreach ($this->edges() as $edge) {
            if ($edge->relation === CausalRelation::CONTRADICTS) {
                return true;
            }
        }
        return false;
    }
}
