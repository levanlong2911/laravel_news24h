<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Performance;

use App\Services\AI\FilmOS\Narrative\Timeline\Clock;
use App\Services\AI\FilmOS\Narrative\Timeline\Events\PerformanceDirectedEvent;
use App\Services\AI\FilmOS\Narrative\Timeline\TimelineOrdinal;
use Illuminate\Support\Str;

/**
 * The only class permitted to create Performance-domain events.
 * Knows no other domain.
 */
final class PerformanceEventFactory
{
    public function __construct(private readonly Clock $clock) {}

    public function directed(
        PerformanceDesign $design,
        string            $productionId = 'default',
        int               $ordinal = TimelineOrdinal::BASELINE,
    ): PerformanceDirectedEvent {
        return new PerformanceDirectedEvent(
            eventId:     (string) Str::ulid(),
            aggregateId: "performance:{$productionId}",
            shotOrdinal: $ordinal,
            occurredAt:  $this->clock->now(),
            design:      $design,
        );
    }
}
