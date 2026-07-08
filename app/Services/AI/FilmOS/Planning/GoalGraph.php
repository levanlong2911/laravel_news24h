<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning;

use App\Services\AI\FilmOS\Graph\Graph;

/**
 * @extends Graph<GoalNode, GoalEdge>
 */
final class GoalGraph extends Graph
{
    public function __construct(
        public readonly string $rootNodeId,
    ) {}

    public function root(): GoalNode
    {
        return $this->node($this->rootNodeId);
    }

    /** @return GoalNode[] leaf nodes only */
    public function leaves(): array
    {
        return array_values(array_filter(
            $this->nodes(),
            fn(GoalNode $n) => $n->isLeaf(),
        ));
    }

    /** @return string[] node IDs of direct REQUIRES prerequisites for the given node */
    public function prerequisites(string $nodeId): array
    {
        $prereqs = [];
        foreach ($this->edges() as $edge) {
            if ($edge->toId === $nodeId && $edge->relation === GoalRelation::REQUIRES) {
                $prereqs[] = $edge->fromId;
            }
        }
        return $prereqs;
    }

    public function totalShots(): int
    {
        return array_sum(array_map(
            fn(GoalNode $n) => $n->maxShots,
            $this->leaves(),
        ));
    }
}
