<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline;

interface TimelineProjector
{
    /**
     * Pure function. No side effects. No DB. No cache.
     * D0–D4: $upToOrdinal = null (full projection).
     * D5 QA+Repair: $upToOrdinal = N to project state before shot N.
     */
    public function project(
        SemanticTimeline $timeline,
        ?int             $upToOrdinal = null,
    ): NarrativeState;
}
