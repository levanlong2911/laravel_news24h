<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\EventBus;

/**
 * Base contract for all FilmOS domain events.
 *
 * Events are the primary coupling mechanism between modules.
 * A module emits events; other modules subscribe — no direct calls.
 */
interface FilmOSEvent
{
    /** Stable, dot-separated name (e.g. "execution.node.failed"). */
    public function eventName(): string;

    /** Unix timestamp with microsecond precision when the event occurred. */
    public function occurredAt(): float;

    /** Serialisable payload for logging, replay, and debugging. */
    public function payload(): array;

    /**
     * Structural fields for deterministic hashing (Phase E EventLayerBuilder).
     *
     * Include:  stable structural fields — taskId, capability.value, counts, booleans.
     * Exclude:  executionId (run-instance), occurredAt (timestamp), errorMessage
     *           (non-deterministic text), elapsedMs / estimatedCostUsd (timing/cost),
     *           nodeId (run-instance UUID), quotaRemaining (runtime state).
     *
     * Two identical runs with the same plan and inputs MUST return identical arrays.
     *
     * @return array<string, mixed>
     */
    public function canonicalData(): array;
}
