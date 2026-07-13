<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Production;

use App\Services\AI\FilmOS\Planning\PlanningIR;

interface VideoProductionPipeline
{
    /** @param array<string, PlanningIR> $planningIRs */
    public function produce(
        array     $planningIRs,
        string    $productionId,
        string    $outputDir,
        ?callable $onProgress = null,
    ): ProductionResult;
}
