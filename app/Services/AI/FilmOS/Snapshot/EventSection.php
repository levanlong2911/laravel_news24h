<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

/**
 * Phase E snapshot section — EventBus layer.
 *
 * Hash contract (ADR-016 Phase E):
 *
 *   eventBusHash — ordered list of (eventName, ordinal, canonicalData) tuples
 *                  in emission order. Captures the semantic event sequence of
 *                  an execution run, independent of timing or run-instance IDs.
 *
 * Two runs with the same plan and identical execution paths MUST produce the
 * same eventBusHash. A run that fails at a different node, or that triggers
 * CapabilityResolvedEvent for a different provider, produces a different hash.
 *
 * Excluded from all event canonical data:
 *   occurredAt, executionId, errorMessage, elapsedMs, estimatedCostUsd,
 *   quotaRemaining, nodeId (run-instance UUID)
 */
final class EventSection implements SnapshotSection
{
    public function __construct(
        public readonly string $eventBusHash,
    ) {}

    public static function name(): string { return 'event'; }

    public static function requiredFields(): array
    {
        return ['eventBusHash'];
    }

    public static function optionalFields(): array { return []; }

    public function fields(): array
    {
        return ['eventBusHash' => $this->eventBusHash];
    }
}
