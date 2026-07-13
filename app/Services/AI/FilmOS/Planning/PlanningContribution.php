<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning;

interface PlanningContribution
{
    public function pluginName(): string;

    /** @return PlanningField[] */
    public function toFields(): array;
}
