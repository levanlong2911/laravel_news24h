<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Pipeline;

use App\Services\AI\FilmOS\Planning\PlanningIR;
use App\Services\AI\FilmOS\Production\ProductionResult;
use App\Services\AI\FilmOS\Production\VideoProductionPipeline;
use App\Services\AI\FilmOS\Prompt\PromptCompiler;
use App\Services\AI\FilmOS\Prompt\PromptLearningEngine;
use App\Services\AI\FilmOS\Runtime\RenderRuntime;
use App\Services\AI\FilmOS\Runtime\RuntimeEvent;

/**
 * Legacy-baseline implementation of VideoProductionPipeline.
 *
 * Renders from PlanningIR[] without PromptRuleEngine or learning weights,
 * giving the unoptimised baseline for A/B comparison against FilmOSPipeline.
 *
 * Delete in C.8 once benchmark data shows FilmOS consistently outperforms baseline.
 */
final class LazyLegacyPipeline implements VideoProductionPipeline
{
    public function __construct(
        private readonly PromptCompiler       $promptCompiler,
        private readonly PromptLearningEngine $learningEngine,
        private readonly RenderRuntime        $renderRuntime,
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
            $graph    = $this->promptCompiler->compile($ir);
            $renderIR = $this->learningEngine->compile($graph); // no rules applied
            $result   = $this->renderRuntime->run($renderIR);

            $shots[] = new RenderedShot(
                shotId:    $ir->shotId,
                shotOrder: $ir->shotOrder,
                status:    $result->status,
                assetUrl:  $result->assetUrl ?: null,
                error:     $result->status !== RuntimeEvent::DOWNLOAD_COMPLETED
                               ? $result->status->value
                               : null,
            );

            $log($result->status === RuntimeEvent::DOWNLOAD_COMPLETED
                ? "  ✓ legacy [{$shotId}]"
                : "  ✗ legacy [{$shotId}] {$result->status->value}");
        }

        return $this->assembler->assemble($shots, $outputDir, $productionId);
    }
}
