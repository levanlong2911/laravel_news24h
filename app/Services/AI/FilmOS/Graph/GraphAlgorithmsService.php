<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Graph;

/**
 * Stateful wrapper around GraphAlgorithms static utility.
 * Held by GraphEngine — allows test injection via GraphEngine::withAlgorithms().
 */
final class GraphAlgorithmsService
{
    /** @return GraphNode[] topologically sorted */
    public function topoSort(Graph $graph): array
    {
        return GraphAlgorithms::topoSort($graph);
    }

    public function detectCycle(Graph $graph): bool
    {
        return GraphAlgorithms::detectCycle($graph);
    }

    /** @return array<GraphNode[]> */
    public function connectedComponents(Graph $graph): array
    {
        return GraphAlgorithms::connectedComponents($graph);
    }
}
