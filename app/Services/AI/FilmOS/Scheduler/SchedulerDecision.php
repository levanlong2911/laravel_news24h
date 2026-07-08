<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Scheduler;

use App\Services\AI\FilmOS\Capability\CapabilityType;

/**
 * The scheduler's answer to "which provider should run this capability now?"
 * Immutable value object — created by ResourceScheduler, consumed by Planner.
 */
final class SchedulerDecision
{
    public function __construct(
        public readonly string         $provider,
        public readonly CapabilityType $capability,
        public readonly float          $estimatedCostUsd,
        public readonly int            $quotaUsedBefore,
        public readonly int            $quotaMax,
    ) {}

    public function quotaRemaining(): int
    {
        return max(0, $this->quotaMax - $this->quotaUsedBefore - 1);
    }

    public function quotaPercentUsed(): float
    {
        if ($this->quotaMax === PHP_INT_MAX || $this->quotaMax === 0) {
            return 0.0;
        }
        return (($this->quotaUsedBefore + 1) / $this->quotaMax) * 100;
    }

    public function isUnlimited(): bool
    {
        return $this->quotaMax === PHP_INT_MAX;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'provider'         => $this->provider,
            'capability'       => $this->capability->value,
            'estimatedCost'    => $this->estimatedCostUsd,
            'quotaUsedBefore'  => $this->quotaUsedBefore,
            'quotaMax'         => $this->isUnlimited() ? 'unlimited' : $this->quotaMax,
            'quotaRemaining'   => $this->isUnlimited() ? 'unlimited' : $this->quotaRemaining(),
        ];
    }
}
