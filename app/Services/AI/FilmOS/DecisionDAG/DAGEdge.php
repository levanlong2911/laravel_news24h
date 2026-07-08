<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\DecisionDAG;

use App\Services\AI\FilmOS\Graph\GraphEdge;

final class DAGEdge extends GraphEdge
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
}
