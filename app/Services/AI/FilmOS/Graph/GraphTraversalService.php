<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Graph;

/**
 * Stateful wrapper around GraphTraversal static utility.
 * Held by GraphEngine.
 */
final class GraphTraversalService
{
    /** @return GraphNode[] */
    public function bfs(Graph $graph, string $startId): array
    {
        return GraphTraversal::bfs($graph, $startId);
    }

    /** @return GraphNode[] */
    public function dfs(Graph $graph, string $startId): array
    {
        return GraphTraversal::dfs($graph, $startId);
    }

    /** @return string[] */
    public function traceBack(Graph $graph, string $startId, callable $stopCondition): array
    {
        return GraphTraversal::traceBack($graph, $startId, $stopCondition);
    }
}
