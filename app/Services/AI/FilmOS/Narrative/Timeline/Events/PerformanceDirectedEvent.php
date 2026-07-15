<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline\Events;

use App\Services\AI\FilmOS\Narrative\Performance\PerformanceDesign;
use App\Services\AI\FilmOS\Narrative\Timeline\SemanticEvent;

/**
 * The production's acting was DIRECTED — one event for the whole design.
 *
 * Named "Directed" (not "Planned") on purpose: Production PLANS staging,
 * Performance DIRECTS acting. The timeline reads like a film workflow:
 *   StoryPlanned → ProductionPlanned → PerformanceDirected.
 *
 * One event, not per-cue events (the D1 rule: events describe acts,
 * not properties).
 *
 * INVARIANT: one design per production. Projection applies last-write-wins;
 * a duplicate is an anomaly for a future QA rule to flag.
 */
final class PerformanceDirectedEvent implements SemanticEvent
{
    public function __construct(
        private readonly string           $eventId,      // ULID
        private readonly string           $aggregateId,  // "performance:{productionId}"
        private readonly int              $shotOrdinal,  // BASELINE — directed before shot 0
        private readonly int              $occurredAt,
        public readonly PerformanceDesign $design,
    ) {}

    public function eventId(): string     { return $this->eventId; }
    public function version(): int        { return 1; }
    public function aggregateId(): string { return $this->aggregateId; }
    public function shotOrdinal(): int    { return $this->shotOrdinal; }
    public function occurredAt(): int     { return $this->occurredAt; }
}
