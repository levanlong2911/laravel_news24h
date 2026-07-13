<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning;

final class ScenePlanningContribution implements PlanningContribution
{
    /** @param PlanningField[] $fields */
    public function __construct(
        private readonly string $pluginName,
        private readonly array  $fields,
    ) {}

    public function pluginName(): string
    {
        return $this->pluginName;
    }

    /** @return PlanningField[] */
    public function toFields(): array
    {
        return $this->fields;
    }
}
