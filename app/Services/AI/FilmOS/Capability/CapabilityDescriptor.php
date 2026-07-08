<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Capability;

/**
 * Describes a single provider's support for a specific capability.
 *
 * Registered into CapabilityRegistry. Planner reads descriptors —
 * it never references provider names directly.
 */
final class CapabilityDescriptor
{
    public function __construct(
        public readonly string         $providerName,
        public readonly CapabilityType $capability,
        /** Higher priority = preferred. Registry sorts descending. */
        public readonly int            $priority = 100,
        /** Estimated cost per API call in USD. */
        public readonly float          $costPerCallUsd = 0.0,
        /** Daily quota (PHP_INT_MAX = unlimited). */
        public readonly int            $dailyQuota = PHP_INT_MAX,
        /** Arbitrary metadata for planner hints (e.g. max resolution, fps). */
        public readonly array          $metadata = [],
    ) {}

    public function isUnlimited(): bool
    {
        return $this->dailyQuota === PHP_INT_MAX;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'provider'       => $this->providerName,
            'capability'     => $this->capability->value,
            'priority'       => $this->priority,
            'costPerCallUsd' => $this->costPerCallUsd,
            'dailyQuota'     => $this->isUnlimited() ? 'unlimited' : $this->dailyQuota,
            'metadata'       => $this->metadata,
        ];
    }
}
