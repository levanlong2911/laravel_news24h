<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Graph;

/**
 * Query operations on a Graph — filtering, ancestor/descendant traversal.
 * Stateless: takes a Graph, returns results.
 */
final class GraphQuery
{
    /**
     * Find nodes matching a predicate.
     * @param  callable $predicate (GraphNode) => bool
     * @return GraphNode[]
     */
    public static function find(Graph $graph, callable $predicate): array
    {
        return array_values(array_filter($graph->nodes(), $predicate));
    }

    /**
     * Filter edges matching a predicate.
     * @param  callable $predicate (GraphEdge) => bool
     * @return GraphEdge[]
     */
    public static function filterEdges(Graph $graph, callable $predicate): array
    {
        return array_values(array_filter($graph->edges(), $predicate));
    }

    /**
     * All ancestors of a node (nodes that can reach it via directed edges).
     * @return GraphNode[]
     */
    public static function ancestors(Graph $graph, string $nodeId): array
    {
        $parents = [];
        foreach ($graph->edges() as $edge) {
            $parents[$edge->toId][] = $edge->fromId;
        }

        $visited = [];
        $queue   = $parents[$nodeId] ?? [];
        $result  = [];

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
            foreach ($parents[$current] ?? [] as $parentId) {
                if (!isset($visited[$parentId])) {
                    $queue[] = $parentId;
                }
            }
        }

        return $result;
    }

    /**
     * All descendants of a node (nodes reachable from it via directed edges).
     * @return GraphNode[]
     */
    public static function descendants(Graph $graph, string $nodeId): array
    {
        $children = [];
        foreach ($graph->edges() as $edge) {
            $children[$edge->fromId][] = $edge->toId;
        }

        $visited = [];
        $queue   = $children[$nodeId] ?? [];
        $result  = [];

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
            foreach ($children[$current] ?? [] as $childId) {
                if (!isset($visited[$childId])) {
                    $queue[] = $childId;
                }
            }
        }

        return $result;
    }

    /**
     * Nodes with no outgoing edges (sinks / leaves).
     * @return GraphNode[]
     */
    public static function sinks(Graph $graph): array
    {
        $hasOutgoing = [];
        foreach ($graph->edges() as $edge) {
            $hasOutgoing[$edge->fromId] = true;
        }
        return array_values(array_filter(
            $graph->nodes(),
            fn(GraphNode $n) => !isset($hasOutgoing[$n->id]),
        ));
    }

    /**
     * Nodes with no incoming edges (sources / roots).
     * @return GraphNode[]
     */
    public static function sources(Graph $graph): array
    {
        $hasIncoming = [];
        foreach ($graph->edges() as $edge) {
            $hasIncoming[$edge->toId] = true;
        }
        return array_values(array_filter(
            $graph->nodes(),
            fn(GraphNode $n) => !isset($hasIncoming[$n->id]),
        ));
    }

    /**
     * Direct neighbors (1-hop): nodes reachable via one outgoing edge.
     * @return GraphNode[]
     */
    public static function neighbors(Graph $graph, string $nodeId): array
    {
        $result = [];
        foreach ($graph->edges() as $edge) {
            if ($edge->fromId === $nodeId) {
                $n = $graph->node($edge->toId);
                if ($n !== null) {
                    $result[] = $n;
                }
            }
        }
        return $result;
    }

    /**
     * All nodes reachable from startId (BFS, directed).
     * Identical to GraphTraversal::bfs but returns only reachable node IDs
     * as a set — O(1) membership check for Learning patterns.
     * @return array<string, true>  nodeId → true
     */
    public static function reachableSet(Graph $graph, string $startId): array
    {
        $children = [];
        foreach ($graph->edges() as $edge) {
            $children[$edge->fromId][] = $edge->toId;
        }
        $visited = [];
        $queue   = [$startId];
        while (!empty($queue)) {
            $cur = array_shift($queue);
            if (isset($visited[$cur])) {
                continue;
            }
            $visited[$cur] = true;
            foreach ($children[$cur] ?? [] as $child) {
                if (!isset($visited[$child])) {
                    $queue[] = $child;
                }
            }
        }
        unset($visited[$startId]); // exclude self
        return $visited;
    }

    /**
     * Lowest common ancestor of two nodes (BFS from each, find first overlap).
     * Returns null when no common ancestor exists (disconnected or both are roots).
     */
    public static function commonAncestor(Graph $graph, string $aId, string $bId): ?GraphNode
    {
        $parentsOf = static function (string $nodeId) use ($graph): array {
            $parents = [];
            foreach ($graph->edges() as $edge) {
                if ($edge->toId === $nodeId) {
                    $parents[] = $edge->fromId;
                }
            }
            return $parents;
        };

        // BFS upward from each node — find first ID in both ancestor sets
        $ancestorsA = [];
        $queue = [$aId];
        while (!empty($queue)) {
            $cur = array_shift($queue);
            $ancestorsA[$cur] = true;
            foreach ($parentsOf($cur) as $p) {
                if (!isset($ancestorsA[$p])) {
                    $queue[] = $p;
                }
            }
        }

        // BFS upward from B — return first hit in A's ancestor set
        $queue   = [$bId];
        $visited = [];
        while (!empty($queue)) {
            $cur = array_shift($queue);
            if (isset($ancestorsA[$cur]) && $cur !== $aId && $cur !== $bId) {
                return $graph->node($cur);
            }
            $visited[$cur] = true;
            foreach ($parentsOf($cur) as $p) {
                if (!isset($visited[$p])) {
                    $queue[] = $p;
                }
            }
        }

        return null;
    }

    /**
     * Extract a subgraph containing only the given node IDs and the edges between them.
     * Returns [nodes[], edges[]] — not a Graph instance (caller picks the concrete type).
     *
     * @param  string[] $nodeIds
     * @return array{nodes: GraphNode[], edges: GraphEdge[]}
     */
    public static function subgraph(Graph $graph, array $nodeIds): array
    {
        $idSet = array_flip($nodeIds);
        $nodes = array_values(array_filter(
            $graph->nodes(),
            fn(GraphNode $n) => isset($idSet[$n->id]),
        ));
        $edges = array_values(array_filter(
            $graph->edges(),
            fn(GraphEdge $e) => isset($idSet[$e->fromId]) && isset($idSet[$e->toId]),
        ));
        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * Find nodes where a property matches a value (reflection-free, uses public properties).
     * Example: GraphQuery::findByProperty($dag, 'type', DAGNodeType::FACT)
     *
     * @return GraphNode[]
     */
    public static function findByProperty(Graph $graph, string $property, mixed $value): array
    {
        return array_values(array_filter(
            $graph->nodes(),
            function (GraphNode $n) use ($property, $value): bool {
                // @phpstan-ignore-next-line — dynamic property access by design
                return isset($n->$property) && $n->$property === $value;
            },
        ));
    }
}
