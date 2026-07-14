<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative;

use App\Services\AI\FilmOS\Graph\GraphNode;
use App\Services\AI\FilmOS\Narrative\Story\StoryBeat;

final class NarrativeNode extends GraphNode
{
    public function __construct(
        string                    $id,
        public readonly StoryBeat $beat,
        public readonly string    $concept,
        public readonly float     $weight,
    ) {
        parent::__construct($id);
    }

    public function label(): string
    {
        return "{$this->beat->value}:{$this->concept}";
    }
}
