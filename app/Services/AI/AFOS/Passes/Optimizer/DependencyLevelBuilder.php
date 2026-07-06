<?php

namespace App\Services\AI\AFOS\Passes\Optimizer;

use App\Services\AI\AFOS\Passes\Pipeline\CompilerStage;

/**
 * DependencyLevelBuilder — single source of truth for topological level computation.
 *
 * Both PassOptimizer and CostAwareOrderingPass need to group stages by data-dependency
 * depth. This class owns that algorithm so neither class duplicates it.
 *
 * Algorithm (DP on stages in declared topological order):
 *   producedBy[fqcn] = index of the stage that writes that IR type.
 *   level[i] = max(level[dep] + 1) for all reads of stage i that are produced
 *              by some earlier stage. Stages whose reads are all pipeline inputs
 *              (not produced by any stage) land on level 0.
 */
final class DependencyLevelBuilder
{
    /**
     * Compute the topological level index for each stage.
     *
     * @param  CompilerStage[] $stages Must be in valid topological order.
     * @return int[]  Indexed by stage position, value = level (0-based).
     */
    public static function levelIndices(array $stages): array
    {
        // Pass 1: all producers for each IR FQCN (multiple stages may write the same type)
        $producedBy = [];   // fqcn → int[]
        foreach ($stages as $i => $stage) {
            foreach ($stage->metadata()->writes as $write) {
                $producedBy[$write][] = $i;
            }
        }

        // Pass 2: DP — level[i] = max(level[producer] + 1) over all upstream producers
        $levels = array_fill(0, count($stages), 0);
        foreach ($stages as $i => $stage) {
            foreach ($stage->metadata()->reads as $read) {
                if (!isset($producedBy[$read])) {
                    continue;
                }
                foreach ($producedBy[$read] as $producerIdx) {
                    if ($producerIdx === $i) {
                        continue; // skip self (transform stage reads and writes same IR)
                    }
                    $levels[$i] = max($levels[$i], $levels[$producerIdx] + 1);
                }
            }
        }

        return $levels;
    }

    /**
     * Group stages by topological level.
     *
     * @param  CompilerStage[] $stages
     * @return array<int, CompilerStage[]>  Keyed by level index, sorted ascending.
     */
    public static function groupByLevel(array $stages): array
    {
        if (empty($stages)) {
            return [];
        }

        $indices = self::levelIndices($stages);
        $groups  = [];
        foreach ($stages as $i => $stage) {
            $groups[$indices[$i]][] = $stage;
        }
        ksort($groups);

        return $groups;
    }
}
