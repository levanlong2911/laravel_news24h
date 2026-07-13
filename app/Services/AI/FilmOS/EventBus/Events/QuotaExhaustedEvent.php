<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\EventBus\Events;

use App\Services\AI\FilmOS\Capability\CapabilityType;
use App\Services\AI\FilmOS\EventBus\AbstractFilmOSEvent;

final class QuotaExhaustedEvent extends AbstractFilmOSEvent
{
    public function __construct(
        public readonly CapabilityType $capability,
        /** All providers exhausted — null means no fallback was found. */
        public readonly ?string        $lastProviderAttempted,
    ) {
        parent::__construct();
    }

    public function eventName(): string
    {
        return 'capability.quota.exhausted';
    }

    public function payload(): array
    {
        return [
            'capability'            => $this->capability->value,
            'lastProviderAttempted' => $this->lastProviderAttempted,
        ];
    }

    public function canonicalData(): array
    {
        return [
            'capability'            => $this->capability->value,
            'lastProviderAttempted' => $this->lastProviderAttempted,
        ];
    }
}
