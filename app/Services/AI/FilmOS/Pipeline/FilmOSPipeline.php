<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Pipeline;

use App\Services\AI\FilmOS\Planning\PlanningIR;
use App\Services\AI\FilmOS\Production\ProductionResult;
use App\Services\AI\FilmOS\Production\VideoProductionPipeline;

/**
 * FilmOS implementation of VideoProductionPipeline.
 *
 * Receives PlanningIR[] (conversion from DirectorIntent happens in ProduceCommand,
 * outside this pipeline). Iterates shots, delegates per-shot work to ShotRenderService,
 * then hands collected shots to RenderAssetAssembler for download + merge.
 *
 * Does NOT compile prompts, does NOT know about Kling, does NOT download clips directly.
 * Sequential in C.7; swap ShotRenderService to parallelize in C.8.
 */
final class FilmOSPipeline implements VideoProductionPipeline
{
    public function __construct(
        private readonly ShotRenderService    $shotRender,
        private readonly RenderAssetAssembler $assembler,
    ) {}

    public function produce(
        array     $planningIRs,
        string    $productionId,
        string    $outputDir,
        ?callable $onProgress = null,
    ): ProductionResult {
        $log   = $onProgress ?? static fn (string $_) => null;
        $shots = [];

        foreach ($planningIRs as $shotId => $ir) {
            /** @var PlanningIR $ir */
            $shot    = $this->shotRender->render($ir);
            $shots[] = $shot;
            $log($shot->isSuccess() ? "  ✓ [{$shotId}]" : "  ✗ [{$shotId}] {$shot->status->value}");
        }

        return $this->assembler->assemble($shots, $outputDir, $productionId);
    }
}
