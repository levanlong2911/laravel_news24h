<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline;

final class ProjectionMetadata
{
    public function __construct(
        public readonly float $projectionTimeMs, // wall-clock ms for the project() call
        public readonly int   $eventCount,       // total events replayed
        public readonly int   $lastOrdinal,      // highest shotOrdinal seen (-1 if empty)
        public readonly int   $generatedAt,      // unix timestamp
    ) {}

    // schemaVersion lives on NarrativeState::$schemaVersion — not duplicated here.
}
