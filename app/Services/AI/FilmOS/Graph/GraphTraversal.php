<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Graph;

/**
 * Stateless graph traversal algorithms.
 * Works on any Graph regardless of domain node/edge types.
 */
final class GraphTraversal
{
    /**
     * Breadth-first search from startId.
     * @return GraphNode[] in BFS order
     */
    public static function bfs(Graph $graph, string $startId): array
    {
        $visited = [];
        $queue   = [$startId];
        $result  = [];

        $adjacency = self::buildAdjacency($graph);

        while (!empty($queue)) {
            $current = array_shift($queue);
            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;
            $node = $graph->node($current);
            if ($node !== null) {
                $result[] = $node;
            }
            foreach ($adjacency[$current] ?? [] as $neighbor) {
                if (!isset($visited[$neighbor])) {
                    $queue[] = $neighbor;
                }
            }
        }

        return $result;
    }

    /**
     * Depth-first search from startId.
     * @return GraphNode[] in DFS order
     */
    public static function dfs(Graph $graph, string $startId): array
    {
        $visited = [];
        $result  = [];
        self::dfsVisit($graph, $startId, $visited, $result, self::buildAdjacency($graph));
        return $result;
    }

    /**
     * Trace back from a node toward root nodes, following edges in reverse.
     * Stops when $stopCondition returns true for a node.
     *
     * @param  callable $stopCondition (GraphNode) => bool — stop when this returns true
     * @return string[] chain of node IDs from startId back toward root
     */
    public static function traceBack(Graph $graph, string $startId, callable $stopCondition = null): array
    {
        // Guard: node must exist in the graph
        if ($graph->node($startId) === null) {
            return [];
        }

        $stopCondition ??= fn(GraphNode $n) => $n->isRoot();

        // Build reverse adjacency (child → parents)
        $parents = [];
        foreach ($graph->edges() as $edge) {
            $parents[$edge->toId][] = $edge->fromId;
        }

        $chain   = [$startId];
        $current = $startId;

        while (isset($parents[$current])) {
            $parentId = $parents[$current][0];
            $chain[]  = $parentId;

            $parentNode = $graph->node($parentId);
            if ($parentNode !== null && $stopCondition($parentNode)) {
                break;
            }
            $current = $parentId;
        }

        return $chain;
    }

    private static function dfsVisit(
        Graph  $graph,
        string $id,
        array  &$visited,
        array  &$result,
        array  $adjacency,
    ): void {
        if (isset($visited[$id])) {
            return;
        }
        $visited[$id] = true;
        $node = $graph->node($id);
        if ($node !== null) {
            $result[] = $node;
        }
        foreach ($adjacency[$id] ?? [] as $neighbor) {
            self::dfsVisit($graph, $neighbor, $visited, $result, $adjacency);
        }
    }

    private static function buildAdjacency(Graph $graph): array
    {
        $adj = [];
        foreach ($graph->nodes() as $node) {
            $adj[$node->id] = [];
        }
        foreach ($graph->edges() as $edge) {
            $adj[$edge->fromId][] = $edge->toId;
        }
        return $adj;
    }
}
