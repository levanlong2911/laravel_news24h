<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\DecisionDAG;

use App\Services\AI\FilmOS\Graph\GraphEdge;
use App\Services\AI\FilmOS\Snapshot\GraphHashable;

final class DAGEdge extends GraphEdge implements GraphHashable
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

    /** @return array<string, string> */
    public function canonicalData(): array
    {
        return ['from' => $this->fromId, 'to' => $this->toId, 'rel' => $this->edgeLabel];
    }
}
