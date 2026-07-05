<?php

namespace App\Services\AI\AFOS\Passes\Optimizer\Passes;

use App\Services\AI\AFOS\Passes\Optimizer\OptimizationContext;
use App\Services\AI\AFOS\Passes\Optimizer\OptimizationPass;
use App\Services\AI\AFOS\Passes\Pipeline\CompilerStage;
use App\Services\AI\AFOS\Passes\Pipeline\StageCapability;

/**
 * DeadStageEliminationPass — removes stages whose outputs are never consumed.
 *
 * Algorithm (reverse traversal):
 *   1. Start with $needed = $context->requiredOutputs
 *   2. Walk stages backwards; for each stage:
 *      - If it has SIDE_EFFECT capability: always keep (writing to external systems)
 *      - If it writes nothing (validation stage): keep in 'full' mode, skip in 'draft'
 *      - Otherwise: keep if any write is in $needed; if kept, add all its reads to $needed
 *   3. Return stages in original order
 *
 * Example — standard pipeline, full mode:
 *   All stages kept (no writes are unused in a linear chain)
 *
 * Example — standard pipeline, draft mode:
 *   ShotValidationStage removed (writes nothing, mode=draft)
 *   CameraValidationStage removed (writes nothing, mode=draft)
 *
 * Example — custom required_outputs=['CompositionIR']:
 *   Tier2, CameraValidation, Tier3, BackendStage eliminated (CompositionIR not needed by them
 *   and compiledPrompt is not required)
 */
final class DeadStageEliminationPass implements OptimizationPass
{
    public function optimize(array $stages, OptimizationContext $context): array
    {
        $needed = array_flip($context->requiredOutputs);

        $kept = [];

        foreach (array_reverse($stages) as $stage) {
            $meta = $stage->metadata();

            // Rule 1: SIDE_EFFECT stages are never eliminated
            if ($meta->hasCapability(StageCapability::SIDE_EFFECT)) {
                $kept[] = $stage;
                foreach ($meta->reads as $r) {
                    $needed[$r] = true;
                }
                continue;
            }

            // Rule 2: Validation stages (writes=[]) — skip in draft mode
            if (empty($meta->writes)) {
                if (!$context->isDraft()) {
                    $kept[] = $stage;
                    foreach ($meta->reads as $r) {
                        $needed[$r] = true;
                    }
                }
                // draft mode: silently remove this stage
                continue;
            }

            // Rule 3: Normal stages — keep only if at least one write is needed
            $isNeeded = false;
            foreach ($meta->writes as $write) {
                if (isset($needed[$write])) {
                    $isNeeded = true;
                    break;
                }
            }

            if ($isNeeded) {
                $kept[] = $stage;
                foreach ($meta->reads as $r) {
                    $needed[$r] = true;
                }
            }
            // else: dead stage — omit
        }

        return array_reverse($kept);
    }

    public function name(): string
    {
        return 'DeadStageElimination';
    }

    public function description(): string
    {
        return 'Removes stages whose outputs are not consumed by any downstream stage. '
             . 'In draft mode, also removes READ_ONLY validation stages for speed.';
    }
}
