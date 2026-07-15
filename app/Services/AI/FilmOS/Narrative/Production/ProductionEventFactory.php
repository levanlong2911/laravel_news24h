<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Production;

use App\Services\AI\FilmOS\Narrative\Timeline\Clock;
use App\Services\AI\FilmOS\Narrative\Timeline\Events\ProductionPlannedEvent;
use App\Services\AI\FilmOS\Narrative\Timeline\TimelineOrdinal;
use Illuminate\Support\Str;

/**
 * The only class permitted to create Production-domain events.
 * Does not know World, Scene, Character, Story, or Planning domains.
 */
final class ProductionEventFactory
{
    public function __construct(private readonly Clock $clock) {}

    public function planned(
        ProductionPlan $plan,
        string         $productionId = 'default',
        int            $ordinal = TimelineOrdinal::BASELINE,
    ): ProductionPlannedEvent {
        return new ProductionPlannedEvent(
            eventId:     (string) Str::ulid(),
            aggregateId: "production:{$productionId}",
            shotOrdinal: $ordinal,
            occurredAt:  $this->clock->now(),
            plan:        $plan,
        );
    }
}
