<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning;

use App\Services\AI\FilmOS\Graph\GraphNode;
use App\Services\AI\FilmOS\Snapshot\GraphHashable;

final class GoalNode extends GraphNode implements GraphHashable
{
    public function __construct(
        string                 $id,
        public readonly string       $description,
        public readonly GoalNodeType $type,
        public readonly float        $priority,
        public readonly int          $maxShots = 1,
    ) {
        parent::__construct($id);
    }

    public function isLeaf(): bool
    {
        return $this->type === GoalNodeType::LEAF;
    }

    public function isRoot(): bool
    {
        return $this->type === GoalNodeType::ROOT;
    }

    public function label(): string
    {
        return "{$this->id} [{$this->type->value}] p={$this->priority}";
    }

    /** @return array<string, string> */
    public function canonicalData(): array
    {
        return ['id' => $this->id, 'type' => $this->type->value];
    }
}
