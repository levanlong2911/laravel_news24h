<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Pipeline;

use App\Services\AI\FilmOS\Planning\PlanningIR;
use App\Services\AI\FilmOS\Prompt\PromptCompiler;
use App\Services\AI\FilmOS\Prompt\PromptLearningEngine;
use App\Services\AI\FilmOS\Prompt\PromptRuleEngine;
use App\Services\AI\FilmOS\Runtime\ProviderResult;
use App\Services\AI\FilmOS\Runtime\RenderRuntime;

/**
 * Façade for the full FilmOS render pipeline: PlanningIR → ProviderResult.
 *
 * Responsibility: orchestrate PromptCompiler → RuleEngine → LearningEngine → RenderRuntime.
 * ShotRenderService calls this and adds benchmarking on top.
 */
final class FilmOSShotRuntime
{
    public function __construct(
        private readonly PromptCompiler       $promptCompiler,
        private readonly PromptRuleEngine     $ruleEngine,
        private readonly PromptLearningEngine $learningEngine,
        private readonly RenderRuntime        $renderRuntime,
    ) {}

    public function run(PlanningIR $ir): ProviderResult
    {
        $graph    = $this->promptCompiler->compile($ir);
        $graph    = $this->ruleEngine->apply($graph);
        $renderIR = $this->learningEngine->compile($graph);

        return $this->renderRuntime->run($renderIR);
    }
}
