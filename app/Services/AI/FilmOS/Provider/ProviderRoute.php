<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Provider;

use App\Services\AI\FilmOS\Capability\CapabilityType;

/**
 * The result of routing a task to a provider.
 *
 * Immutable record of which provider was selected for a task and why.
 * ProviderLayerBuilder reads these records to build providerRouteHash and capabilityHash.
 *
 * Hash contracts (ADR-016 Phase C):
 *
 *   capabilityHash    — hashes capability.value only. Stable when provider changes.
 *                       Kling → Veo: capabilityHash unchanged (both TEXT_TO_VIDEO).
 *
 *   providerRouteHash — hashes (taskId, providerId). Changes when Kling → Veo.
 *                       This is the signal that the routing decision changed.
 */
final class ProviderRoute
{
    public function __construct(
        public readonly string         $taskId,
        public readonly CapabilityType $capability,
        public readonly string         $providerId,
        public readonly float          $estimatedCostUsd   = 0.0,
        public readonly float          $estimatedLatencyMs = 0.0,
    ) {}

    /**
     * Fields that go into capabilityHash: capability only, NOT providerId.
     * Two routes for the same capability on different providers produce the same capabilityData.
     */
    public function capabilityData(): array
    {
        return [
            'taskId'     => $this->taskId,
            'capability' => $this->capability->value,
        ];
    }

    /**
     * Fields that go into providerRouteHash: taskId + providerId.
     * Changes when Kling is swapped for Veo.
     */
    public function routeData(): array
    {
        return [
            'taskId'     => $this->taskId,
            'providerId' => $this->providerId,
        ];
    }
}
