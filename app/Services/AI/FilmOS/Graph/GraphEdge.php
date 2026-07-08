<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Graph;

/**
 * Base for all FilmOS graph edges.
 * Domain edges override isDependency() to specify which relations
 * create ordering constraints for topological sort.
 */
abstract class GraphEdge
{
    public function __construct(
        public readonly string $fromId,
        public readonly string $toId,
    ) {}

    /**
     * Whether this edge creates an ordering constraint (fromId must come before toId).
     * Used by GraphAlgorithms::topoSort().
     *
     * Default: all edges are dependencies.
     * Override in domain edges to exclude non-ordering relations
     * (e.g. GoalEdge: only REQUIRES is a dependency, not SUPPORTS).
     */
    public function isDependency(): bool
    {
        return true;
    }

    public function label(): string
    {
        return "{$this->fromId} → {$this->toId}";
    }
}
