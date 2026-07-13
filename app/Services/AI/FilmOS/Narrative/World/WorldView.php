<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\World;

/**
 * Read-only view of the semantic world state at a given timeline position.
 * D4 (Scene Graph) and D2 (Character Memory) depend on this interface — never on WorldProjection.
 */
interface WorldView
{
    public function hasObject(string $objectId): bool;

    public function getFact(string $key): ?WorldFact;

    /** @return array<string, WorldObject> */
    public function allObjects(): array;

    /** @return array<string, WorldFact> */
    public function allFacts(): array;
}
