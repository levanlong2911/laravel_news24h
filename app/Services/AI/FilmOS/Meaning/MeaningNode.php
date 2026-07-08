<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Meaning;

use App\Services\AI\FilmOS\Graph\GraphNode;

final class MeaningNode extends GraphNode
{
    public function __construct(
        string              $id,
        public readonly string $concept,
        public readonly float  $weight,
        public readonly string $evidence,
    ) {
        parent::__construct($id);
    }

    public function label(): string
    {
        return "{$this->concept} (w={$this->weight})";
    }
}
