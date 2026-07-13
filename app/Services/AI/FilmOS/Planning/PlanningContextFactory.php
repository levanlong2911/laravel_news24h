<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning;

use App\Services\AI\FilmOS\Meaning\MeaningGraph;

final class PlanningContextFactory
{
    /** @return PlanningJob[] */
    public function createJobs(
        GoalGraph    $goals,
        MeaningGraph $meaning,
        string       $domain = '',
        array        $worldState = [],
    ): array {
        $jobs = [];

        foreach ($goals->leaves() as $goalNode) {
            $context = new PlanningContext(
                goalId:      $goalNode->id,
                beat:        str_replace('shot_', '', $goalNode->id),
                subject:     (string) ($worldState['subject']     ?? ''),
                action:      (string) ($worldState['action']      ?? ''),
                environment: (string) ($worldState['environment'] ?? ''),
                domain:      $domain,
                attributes:  ['priority' => $goalNode->priority],
            );

            $jobs[] = new PlanningJob($context, new PlanningLegacyContext($goalNode, $meaning, $worldState));
        }

        return $jobs;
    }
}
