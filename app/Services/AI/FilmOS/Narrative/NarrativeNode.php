<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative;

use App\Services\AI\FilmOS\Graph\GraphNode;

final class NarrativeNode extends GraphNode
{
    public function __construct(
        string               $id,
        public readonly string $beat,    // hook | escalation | reveal | payoff
        public readonly string $concept,
        public readonly float  $weight,
    ) {
        parent::__construct($id);
    }

    public function label(): string
    {
        return "{$this->beat}:{$this->concept}";
    }
}
