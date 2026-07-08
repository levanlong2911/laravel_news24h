<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning;

use App\Services\AI\FilmOS\Graph\GraphEdge;
use App\Services\AI\FilmOS\Snapshot\GraphHashable;

final class GoalEdge extends GraphEdge implements GraphHashable
{
    public function __construct(
        string                 $fromId,
        string                 $toId,
        public readonly GoalRelation $relation,
    ) {
        parent::__construct($fromId, $toId);
    }

    /** Only REQUIRES edges create ordering constraints. SUPPORTS does not block execution. */
    public function isDependency(): bool
    {
        return $this->relation === GoalRelation::REQUIRES;
    }

    public function label(): string
    {
        return "{$this->fromId} —[{$this->relation->value}]→ {$this->toId}";
    }

    /** @return array<string, string> */
    public function canonicalData(): array
    {
        return ['from' => $this->fromId, 'to' => $this->toId, 'rel' => $this->relation->value];
    }
}
