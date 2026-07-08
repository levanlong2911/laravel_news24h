<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Graph;

/**
 * Stateless graph algorithms — works on any Graph regardless of domain type.
 */
final class GraphAlgorithms
{
    /**
     * Kahn's topological sort.
     *
     * Only edges where isDependency() === true are ordering constraints.
     * SOFT edges (e.g., ExecutionRelation::SOFT) are ignored.
     *
     * @return GraphNode[] topologically ordered (parents before children)
     * @throws \RuntimeException if the graph contains a cycle
     */
    public static function topoSort(Graph $graph): array
    {
        $nodes = $graph->nodes();
        $n     = count($nodes);
        if ($n === 0) {
            return [];
        }

        // Build string-keyed inDegree and children maps
        $inDegree = [];
        $children = [];
        foreach ($nodes as $node) {
            $inDegree[$node->id] = 0;
            $children[$node->id] = [];
        }

        foreach ($graph->edges() as $edge) {
            if (!$edge->isDependency()) {
                continue;
            }
            if (!isset($inDegree[$edge->fromId], $inDegree[$edge->toId])) {
                continue;
            }
            $inDegree[$edge->toId]++;
            $children[$edge->fromId][] = $edge->toId;
        }

        // SplQueue: O(1) dequeue vs array_shift O(n)
        $queue = new \SplQueue();
        foreach ($inDegree as $id => $degree) {
            if ($degree === 0) {
                $queue->enqueue($id);
            }
        }

        // Build a lookup map for fast node retrieval by ID
        $nodeMap = [];
        foreach ($nodes as $node) {
            $nodeMap[$node->id] = $node;
        }

        $sorted = [];
        while (!$queue->isEmpty()) {
            $id       = $queue->dequeue();
            $sorted[] = $nodeMap[$id];
            foreach ($children[$id] as $childId) {
                $inDegree[$childId]--;
                if ($inDegree[$childId] === 0) {
                    $queue->enqueue($childId);
                }
            }
        }

        if (count($sorted) !== $n) {
            throw new \RuntimeException(
                'Graph contains a cycle — topological sort is not possible.'
            );
        }

        return $sorted;
    }

    /**
     * Detect if the graph contains a directed cycle.
     * Iterative DFS with white/grey/black coloring — iterative to avoid PHP
     * call-stack pressure on deep linear chains.
     */
    public static function detectCycle(Graph $graph): bool
    {
        // Build string-keyed adjacency list
        $adj = [];
        foreach ($graph->nodes() as $node) {
            $adj[$node->id] = [];
        }
        foreach ($graph->edges() as $edge) {
            if (isset($adj[$edge->fromId])) {
                $adj[$edge->fromId][] = $edge->toId;
            }
        }

        // color: 0=white (unvisited), 1=grey (in stack), 2=black (done)
        $color = array_fill_keys(array_keys($adj), 0);

        // Iterative DFS — stack holds [nodeId, nextChildPos]
        foreach (array_keys($adj) as $start) {
            if ($color[$start] !== 0) {
                continue;
            }
            $color[$start] = 1;
            $stack = [[$start, 0]];

            while (!empty($stack)) {
                $top      = &$stack[count($stack) - 1];
                $id       = $top[0];
                $childPos = $top[1];
                $neighbors = $adj[$id];

                if ($childPos < count($neighbors)) {
                    $neighborId = $neighbors[$childPos];
                    $top[1]++;

                    if ($color[$neighborId] === 1) {
                        return true; // back-edge → cycle
                    }
                    if ($color[$neighborId] === 0) {
                        $color[$neighborId] = 1;
                        $stack[] = [$neighborId, 0];
                    }
                } else {
                    $color[$id] = 2; // black — done
                    array_pop($stack);
                }
            }
        }

        return false;
    }

    /**
     * Returns groups of node IDs that are weakly connected (ignores edge direction).
     * @return array<string[]>
     */
    public static function connectedComponents(Graph $graph): array
    {
        $undirected = [];
        foreach ($graph->nodes() as $node) {
            $undirected[$node->id] = [];
        }
        foreach ($graph->edges() as $edge) {
            $undirected[$edge->fromId][] = $edge->toId;
            $undirected[$edge->toId][]   = $edge->fromId;
        }

        $visited    = [];
        $components = [];

        foreach ($graph->nodes() as $node) {
            if (isset($visited[$node->id])) {
                continue;
            }
            $component = [];
            $queue     = new \SplQueue();
            $queue->enqueue($node->id);
            while (!$queue->isEmpty()) {
                $current = $queue->dequeue();
                if (isset($visited[$current])) {
                    continue;
                }
                $visited[$current] = true;
                $component[]       = $current;
                foreach ($undirected[$current] ?? [] as $neighbor) {
                    if (!isset($visited[$neighbor])) {
                        $queue->enqueue($neighbor);
                    }
                }
            }
            $components[] = $component;
        }

        return $components;
    }


}
