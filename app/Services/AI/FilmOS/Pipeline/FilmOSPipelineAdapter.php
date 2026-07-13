<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Pipeline;

use App\Services\AI\FilmOS\Production\ProductionResult;
use App\Services\AI\FilmOS\Production\VideoProductionPipeline;

/**
 * Routes to the legacy or FilmOS pipeline based on a feature flag resolved at boot time.
 *
 * Only this class reads the flag — callers are unaware of which pipeline is active.
 * Rollback = set FILMOS_ENABLED=false (or unset) and redeploy; no code changes required.
 */
final class FilmOSPipelineAdapter implements VideoProductionPipeline
{
    public function __construct(
        private readonly bool                    $enabled,
        private readonly VideoProductionPipeline $legacy,
        private readonly VideoProductionPipeline $filmOS,
    ) {}

    public function produce(
        array     $planningIRs,
        string    $productionId,
        string    $outputDir,
        ?callable $onProgress = null,
    ): ProductionResult {
        return $this->enabled
            ? $this->filmOS->produce($planningIRs, $productionId, $outputDir, $onProgress)
            : $this->legacy->produce($planningIRs, $productionId, $outputDir, $onProgress);
    }
}
