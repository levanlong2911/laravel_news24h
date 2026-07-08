<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Graph;

/**
 * Stateless graph validation checks.
 */
final class GraphValidation
{
    /**
     * True if any non-root node has no incoming edges (no parent).
     * Root nodes are excluded via GraphNode::isRoot().
     */
    public static function hasOrphans(Graph $graph): bool
    {
        $hasParent = [];
        foreach ($graph->edges() as $edge) {
            $hasParent[$edge->toId] = true;
        }

        foreach ($graph->nodes() as $node) {
            if (!$node->isRoot() && !isset($hasParent[$node->id])) {
                return true;
            }
        }

        return false;
    }

    /**
     * True if the graph contains a directed cycle.
     */
    public static function hasCycles(Graph $graph): bool
    {
        return GraphAlgorithms::detectCycle($graph);
    }

    /**
     * True if the graph is fully connected (one weakly-connected component).
     */
    public static function isConnected(Graph $graph): bool
    {
        $components = GraphAlgorithms::connectedComponents($graph);
        return count($components) <= 1;
    }

    /**
     * Comprehensive validation: no orphans, no cycles, is connected.
     * Returns array of violation messages (empty = valid).
     * @return string[]
     */
    public static function validate(Graph $graph): array
    {
        $errors = [];

        if (self::hasOrphans($graph)) {
            $orphans = self::findOrphanIds($graph);
            $errors[] = 'Orphan nodes (no parent edge): ' . implode(', ', $orphans);
        }

        if (self::hasCycles($graph)) {
            $errors[] = 'Graph contains a directed cycle.';
        }

        return $errors;
    }

    /** @return string[] IDs of orphan nodes */
    public static function findOrphanIds(Graph $graph): array
    {
        $hasParent = [];
        foreach ($graph->edges() as $edge) {
            $hasParent[$edge->toId] = true;
        }

        $orphans = [];
        foreach ($graph->nodes() as $node) {
            if (!$node->isRoot() && !isset($hasParent[$node->id])) {
                $orphans[] = $node->id;
            }
        }

        return $orphans;
    }
}
