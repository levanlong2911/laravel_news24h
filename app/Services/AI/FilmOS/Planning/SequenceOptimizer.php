<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning;

use App\Services\AI\FilmOS\Graph\GraphAlgorithms;

/**
 * Orders PlannedShots by GoalGraph topoSort, assigns positions 1..N.
 */
final class SequenceOptimizer
{
    public function optimize(array $unordered, GoalGraph $goals): array
    {
        // topoSort returns GoalNodes in dependency order (leaves last)
        $leaves = $goals->leaves();
        $leafIds = array_map(fn(GoalNode $n) => $n->id, $leaves);

        // Index unordered shots by subGoalId
        $shotByGoal = [];
        foreach ($unordered as $shot) {
            $shotByGoal[$shot->subGoalId] = $shot;
        }

        // Sort shots by leaf order from topoSort (CONTEXT before EVIDENCE)
        $sorted = [];
        $pos    = 1;

        // Use topoSort leaf order — only leaves appear as shots
        foreach (GraphAlgorithms::topoSort($goals) as $node) {
            if (!$node->isLeaf()) {
                continue;
            }
            if (!isset($shotByGoal[$node->id])) {
                continue;
            }
            $old      = $shotByGoal[$node->id];
            $sorted[] = new PlannedShot(
                position:    $pos++,
                subGoalId:   $old->subGoalId,
                description: $old->description,
                execution:   $old->execution,
                rationale:   $old->rationale,
            );
        }

        // Any shots not in the topoSort (SUPPORTS relation) go at the end
        foreach ($unordered as $shot) {
            if (!in_array($shot->subGoalId, $leafIds, true)) {
                continue;
            }
            $alreadyPlaced = array_filter($sorted, fn($s) => $s->subGoalId === $shot->subGoalId);
            if (empty($alreadyPlaced)) {
                $sorted[] = new PlannedShot(
                    position:    $pos++,
                    subGoalId:   $shot->subGoalId,
                    description: $shot->description,
                    execution:   $shot->execution,
                    rationale:   $shot->rationale,
                );
            }
        }

        return $sorted;
    }
}
