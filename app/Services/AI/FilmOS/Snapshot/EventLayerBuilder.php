<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

use App\Services\AI\FilmOS\EventBus\FilmOSEvent;

/**
 * Builds Phase E snapshot hashes from the EventBus emission log.
 *
 * Data source: FilmOSEvent[] — the ordered list of events dispatched during
 * a production run. Typically collected via EventBus::history() (recordHistory mode)
 * or via a dedicated EventCollector subscribeAll() handler.
 *
 * Hash contract (ADR-016 Phase E):
 *
 *   eventBusHash
 *     Input: (eventName, ordinal, canonicalData()) per event, in emission order.
 *     Ordinal is the 0-based index in the emission sequence — captures order without
 *     timestamps. canonicalData() provides the structural fields of each event.
 *     Two identical runs with the same plan and execution path MUST match.
 *
 * Excluded from all canonical data (enforced by FilmOSEvent::canonicalData() contract):
 *   occurredAt   — timestamp; varies between runs
 *   executionId  — run-instance identifier
 *   nodeId       — run-instance UUID (where applicable)
 *   errorMessage — non-deterministic text
 *   elapsedMs / totalElapsedMs — timing
 *   estimatedCostUsd / quotaRemaining — cost / runtime state
 *
 * Provider routing (chosenProvider, lastProviderAttempted) IS included because it
 * represents a determinism-relevant routing decision visible at the event layer,
 * complementary to (not redundant with) Phase C providerRouteHash.
 */
final class EventLayerBuilder
{
    public function __construct(
        private readonly HashSerializer $serializer = new JsonHashSerializer(),
    ) {}

    /**
     * @param  FilmOSEvent[]  $events  in emission order (EventBus::history() or collector output)
     */
    public function build(array $events): EventSection
    {
        return new EventSection(
            eventBusHash: $this->buildEventBusHash($events),
        );
    }

    // ── Private builder ───────────────────────────────────────────────────────

    /**
     * Hash of the full event sequence.
     *
     * Each event becomes an EventEntry: {eventName, ordinal, data}.
     * array_values() re-indexes so ordinals are always 0-based even if
     * the input array has non-sequential keys.
     * CanonicalArray::deepSort() on each event's canonicalData() prevents
     * insertion-order drift in any nested associative payload.
     */
    private function buildEventBusHash(array $events): string
    {
        $canonical = [];

        foreach (array_values($events) as $ordinal => $event) {
            $canonical[] = (new EventEntry(
                eventName: $event->eventName(),
                ordinal:   $ordinal,
                data:      CanonicalArray::deepSort($event->canonicalData()),
            ))->toArray();
        }

        return $this->serializer->sha256($canonical);
    }
}
