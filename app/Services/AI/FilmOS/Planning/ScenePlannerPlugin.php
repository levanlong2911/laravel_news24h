<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning;

final class ScenePlannerPlugin implements PlanningPlugin
{
    public function __construct(private readonly LegacyScenePlannerAdapter $adapter) {}

    public function name(): string
    {
        return 'scene_planner';
    }

    public function priority(): int
    {
        return 50;
    }

    public function supports(PlanningContext $context): bool
    {
        return true;
    }

    public function plan(PlanningJob $job): PlanningContribution
    {
        return $this->adapter->plan($job);
    }
}
