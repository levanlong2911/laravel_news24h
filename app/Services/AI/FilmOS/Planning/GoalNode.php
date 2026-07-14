<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning;

use App\Services\AI\FilmOS\Graph\GraphNode;
use App\Services\AI\FilmOS\Narrative\Story\StoryBeat;
use App\Services\AI\FilmOS\Snapshot\CanonicalNode;
use App\Services\AI\FilmOS\Snapshot\HashableNode;

final class GoalNode extends GraphNode implements HashableNode
{
    public function __construct(
        string                 $id,
        public readonly string       $description,
        public readonly GoalNodeType $type,
        public readonly float        $priority,
        public readonly int          $maxShots = 1,
        /** Cinematic beat carried from NarrativeNode — pass-through, never derived from $id. */
        public readonly ?StoryBeat   $beat = null,
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

    public function canonicalNode(): CanonicalNode
    {
        return new CanonicalNode(
            id:   $this->id,
            type: $this->type->value,
            // maxShots is structural — it determines how many shots this goal produces.
            // A change from maxShots=1 to maxShots=2 must produce a different goalGraphHash.
            data: ['maxShots' => $this->maxShots],
        );
    }
}
