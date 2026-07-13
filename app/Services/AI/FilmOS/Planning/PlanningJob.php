<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning;

final class PlanningJob
{
    public function __construct(
        private readonly PlanningContext        $context,
        private readonly ?PlanningLegacyContext $legacyContext = null,
    ) {}

    public function context(): PlanningContext
    {
        return $this->context;
    }

    public function legacyContext(): ?PlanningLegacyContext
    {
        return $this->legacyContext;
    }
}
