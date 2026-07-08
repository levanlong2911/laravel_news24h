<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Meaning;

use App\Services\AI\FilmOS\Graph\GraphEdge;

final class MeaningEdge extends GraphEdge
{
    public function __construct(
        string                 $fromId,
        string                 $toId,
        public readonly CausalRelation $relation,
        public readonly float          $strength,
    ) {
        parent::__construct($fromId, $toId);
    }

    public function label(): string
    {
        return "{$this->fromId} —[{$this->relation->value}:{$this->strength}]→ {$this->toId}";
    }
}
