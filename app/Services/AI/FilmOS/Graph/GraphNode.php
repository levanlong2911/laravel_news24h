<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Graph;

/**
 * Base for all FilmOS graph nodes.
 * Every domain node (MeaningNode, GoalNode, DAGNode, ExecutionNode) extends this.
 * Enforces Invariant 1: everything meaningful is a graph node.
 */
abstract class GraphNode
{
    public function __construct(
        public readonly string $id,
    ) {}

    /**
     * Whether this node is a structural root that does not require a parent edge.
     * Root nodes are excluded from orphan detection.
     * Override per domain (e.g. DAGNode returns true when type === FACT).
     */
    public function isRoot(): bool
    {
        return false;
    }

    public function label(): string
    {
        return $this->id;
    }
}
