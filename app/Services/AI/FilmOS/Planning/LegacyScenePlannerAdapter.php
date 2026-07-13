<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning;

final class LegacyScenePlannerAdapter
{
    public function __construct(private readonly SubGoalPlanner $subGoalPlanner) {}

    public function plan(PlanningJob $job): ScenePlanningContribution
    {
        $legacy = $job->legacyContext();

        $shot = $this->subGoalPlanner->plan(
            $legacy->goalNode,
            $legacy->meaning,
            $legacy->worldState,
        );

        return new ScenePlanningContribution(
            pluginName: 'legacy_scene_planner',
            fields:     $this->mapShotToFields($shot),
        );
    }

    /** @return PlanningField[] */
    private function mapShotToFields(PlannedShot $shot): array
    {
        $fields = [
            new PlanningField(
                key:    'render.description',
                value:  $shot->description,
                source: 'legacy_scene_planner',
            ),
        ];

        foreach ($shot->execution as $key => $value) {
            $fields[] = new PlanningField(
                key:    'render.' . $key,
                value:  $value instanceof \BackedEnum ? $value->value : $value,
                source: 'legacy_scene_planner',
            );
        }

        return $fields;
    }
}
