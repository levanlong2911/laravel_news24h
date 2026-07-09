<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\ExecutionGraph;

use App\Services\AI\FilmOS\Graph\GraphEdge;
use App\Services\AI\FilmOS\Snapshot\CanonicalEdge;
use App\Services\AI\FilmOS\Snapshot\HashableEdge;

/**
 * Dependency edge trong ExecutionGraph.
 * isDependency() = true chỉ với REQUIRES — SOFT không block execution.
 */
final class ExecutionEdge extends GraphEdge implements HashableEdge
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

    /** Phase B contract: rel = relation.value (REQUIRES | SOFT). */
    public function canonicalEdge(): CanonicalEdge
    {
        return new CanonicalEdge(from: $this->fromId, to: $this->toId, rel: $this->relation->value);
    }
}
