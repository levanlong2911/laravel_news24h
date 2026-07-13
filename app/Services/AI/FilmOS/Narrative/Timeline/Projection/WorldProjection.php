<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline\Projection;

use App\Services\AI\FilmOS\Narrative\World\WorldFact;
use App\Services\AI\FilmOS\Narrative\World\WorldObject;
use App\Services\AI\FilmOS\Narrative\World\WorldView;

/**
 * Snapshot of semantic world state at a given point in the timeline.
 *
 * Invariant: represents the LATEST semantic truth only — not event history.
 * For history, replay SemanticTimeline. For current state, read this projection.
 *
 * D4 (Scene Graph) and D2 (Character Memory) MUST depend on WorldView, not this class.
 */
final class WorldProjection implements WorldView
{
    /**
     * @param array<string, WorldObject> $objects  keyed by objectId
     * @param array<string, WorldFact>   $facts    keyed by factKey (last-write-wins)
     */
    public function __construct(
        public readonly array $objects = [],
        public readonly array $facts   = [],
    ) {}

    public function hasObject(string $objectId): bool
    {
        return isset($this->objects[$objectId]);
    }

    public function getFact(string $key): ?WorldFact
    {
        return $this->facts[$key] ?? null;
    }

    /** @return array<string, WorldObject> */
    public function allObjects(): array
    {
        return $this->objects;
    }

    /** @return array<string, WorldFact> */
    public function allFacts(): array
    {
        return $this->facts;
    }
}
