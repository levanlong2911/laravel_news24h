<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\ExecutionGraph;

use App\Services\AI\FilmOS\Graph\GraphEdge;

/**
 * Dependency edge trong ExecutionGraph.
 * isDependency() = true chỉ với REQUIRES — SOFT không block execution.
 */
final class ExecutionEdge extends GraphEdge
{
    public function __construct(
        string                          $fromId,
        string                          $toId,
        public readonly ExecutionRelation $relation = ExecutionRelation::REQUIRES,
    ) {
        parent::__construct($fromId, $toId);
    }

    /** Hard dependency → block child nếu parent FAILED. */
    public function isDependency(): bool
    {
        return $this->relation === ExecutionRelation::REQUIRES;
    }

    public function label(): string
    {
        return "{$this->fromId} -{$this->relation->value}→ {$this->toId}";
    }
}
