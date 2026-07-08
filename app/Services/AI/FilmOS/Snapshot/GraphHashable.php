<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Snapshot;

/**
 * Marks a graph node or edge as providing a stable, canonical representation
 * for deterministic hashing — independent of runtime state or display text.
 *
 * Rules for implementors:
 *  - Include only STRUCTURAL fields: id, type enum value, relation enum value
 *  - Exclude RUNTIME fields: confidence, status, timestamps, retry counts
 *  - Exclude DISPLAY fields: description, rationale, label text, priority floats
 *
 * Phase C canonical contract for CapabilityNode / ProviderNode:
 *   hash (nodeId, capabilityType.value) — NOT display name, description, version string
 *
 * Phase D canonical contract for EventNode:
 *   hash (eventType.value, sourceNodeId) in emission order — NOT timestamp, uuid, payload
 */
interface GraphHashable
{
    /**
     * Returns only the structural fields that identify this node/edge uniquely
     * and deterministically across all runs with identical inputs.
     *
     * @return array<string, string|int|null>
     */
    public function canonicalData(): array;
}
