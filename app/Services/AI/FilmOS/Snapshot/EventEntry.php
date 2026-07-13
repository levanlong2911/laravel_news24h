<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

/**
 * Canonical DTO for one event in the EventBus log (Phase E).
 *
 * Analogous to CheckpointEntry (Phase B) — separates the canonical hash
 * representation from the runtime FilmOSEvent domain object.
 *
 * Fields in the canonical representation:
 *   eventName — stable dot-separated event type (e.g. "execution.node.failed")
 *   ordinal   — 0-based emission position; captures sequence without timestamps
 *   data      — canonicalData() output from the event, deep-sorted for determinism
 *
 * Excluded from hash (same exclusion rules as FilmOSEvent::canonicalData()):
 *   occurredAt   — timestamp; varies between runs
 *   executionId  — run-instance identifier
 *   errorMessage — non-deterministic text
 *   elapsedMs    — timing; excluded from all Phase E hashes
 */
final class EventEntry
{
    public function __construct(
        public readonly string $eventName,
        public readonly int    $ordinal,
        public readonly array  $data,     // canonicalData() output, already deep-sorted
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'eventName' => $this->eventName,
            'ordinal'   => $this->ordinal,
            'data'      => $this->data,
        ];
    }
}
