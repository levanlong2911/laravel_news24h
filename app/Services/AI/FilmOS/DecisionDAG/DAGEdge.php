<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\DecisionDAG;

use App\Services\AI\FilmOS\Graph\GraphEdge;
use App\Services\AI\FilmOS\Snapshot\CanonicalEdge;
use App\Services\AI\FilmOS\Snapshot\HashableEdge;

final class DAGEdge extends GraphEdge implements HashableEdge
{
    public function __construct(
        string              $fromId,
        string              $toId,
        public readonly string $edgeLabel = 'caused',
    ) {
        parent::__construct($fromId, $toId);
    }

    public function label(): string
    {
        return "{$this->fromId} —[{$this->edgeLabel}]→ {$this->toId}";
    }

    public function canonicalEdge(): CanonicalEdge
    {
        return new CanonicalEdge(from: $this->fromId, to: $this->toId, rel: $this->edgeLabel);
    }
}
