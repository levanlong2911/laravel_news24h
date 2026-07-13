<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\EventBus\Events;

use App\Services\AI\FilmOS\Capability\CapabilityType;
use App\Services\AI\FilmOS\EventBus\AbstractFilmOSEvent;

final class CapabilityResolvedEvent extends AbstractFilmOSEvent
{
    public function __construct(
        public readonly CapabilityType $capability,
        public readonly string         $chosenProvider,
        public readonly int            $quotaRemaining,
        public readonly float          $estimatedCostUsd,
    ) {
        parent::__construct();
    }

    public function eventName(): string
    {
        return 'capability.resolved';
    }

    public function payload(): array
    {
        return [
            'capability'       => $this->capability->value,
            'chosenProvider'   => $this->chosenProvider,
            'quotaRemaining'   => $this->quotaRemaining,
            'estimatedCostUsd' => $this->estimatedCostUsd,
        ];
    }

    public function canonicalData(): array
    {
        return [
            'capability'     => $this->capability->value,
            'chosenProvider' => $this->chosenProvider,
            // excluded: quotaRemaining (runtime state), estimatedCostUsd (non-deterministic cost)
        ];
    }
}
