<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline\Events;

use App\Services\AI\FilmOS\Narrative\Production\ProductionPlan;
use App\Services\AI\FilmOS\Narrative\Timeline\SemanticEvent;

/**
 * The production's staging plan was decided — ONE event for the whole plan.
 *
 * Deliberately not split into MotifAdded/EnergyChanged/HeroMomentDefined…:
 * events describe things that HAPPEN, not properties (the D1 rule). Planning
 * a production is one act; the plan is its payload.
 *
 * INVARIANT: one plan per production. Projection applies last-write-wins;
 * a duplicate is a staging anomaly for a future QA rule to flag.
 */
final class ProductionPlannedEvent implements SemanticEvent
{
    public function __construct(
        private readonly string        $eventId,      // ULID
        private readonly string        $aggregateId,  // "production:{productionId}"
        private readonly int           $shotOrdinal,  // BASELINE — staging decided before shot 0
        private readonly int           $occurredAt,
        public readonly ProductionPlan $plan,
    ) {}

    public function eventId(): string     { return $this->eventId; }
    public function version(): int        { return 1; }
    public function aggregateId(): string { return $this->aggregateId; }
    public function shotOrdinal(): int    { return $this->shotOrdinal; }
    public function occurredAt(): int     { return $this->occurredAt; }
}
