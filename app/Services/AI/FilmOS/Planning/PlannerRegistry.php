<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning;

final class PlannerRegistry
{
    /** @param PlanningPlugin[] $plugins */
    public function __construct(private readonly array $plugins = []) {}

    /** @return PlanningPlugin[] sorted by priority descending */
    public function plugins(): array
    {
        $sorted = $this->plugins;
        usort($sorted, fn (PlanningPlugin $a, PlanningPlugin $b) => $b->priority() <=> $a->priority());
        return $sorted;
    }
}
