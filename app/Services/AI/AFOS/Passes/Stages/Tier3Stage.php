<?php

namespace App\Services\AI\AFOS\Passes\Stages;

use App\Services\AI\AFOS\Creative\Intent;
use App\Services\AI\AFOS\Ir\CameraIR;
use App\Services\AI\AFOS\Ir\CompositionIR;
use App\Services\AI\AFOS\Ir\PromptIR;
use App\Services\AI\AFOS\Passes\Pipeline\CompilerStage;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineState;
use App\Services\AI\AFOS\Passes\Pipeline\StageCapability;
use App\Services\AI\AFOS\Passes\Pipeline\StageCost;
use App\Services\AI\AFOS\Passes\Pipeline\StageMetadata;
use App\Services\AI\AFOS\Passes\Prompt\KlingPromptPlanningPass;

/**
 * Tier3Stage — CameraIR + CompositionIR + Intent → PromptIR.
 *
 * Backend-specific: currently hardwired to Kling's prompt planning pass.
 * Future: accept a PromptPlanningPass interface and resolve by backendId.
 */
final class Tier3Stage implements CompilerStage
{
    public function __construct(
        private readonly KlingPromptPlanningPass $pass,
    ) {}

    public function run(PipelineState $state): PipelineState
    {
        $promptIn = [
            'camera_ir'      => $state->camera->toArray(),
            'composition_ir' => $state->composition->toArray(),
        ];

        $promptIR = $this->pass->run($state->camera, $state->composition, $state->intent);
        $state->trace?->record('prompt_ir', $promptIR->toArray());
        $state->trace?->recordPass($this->pass->name(), $promptIn, $promptIR->toArray(), []);

        return $state->withPromptIR($promptIR);
    }

    public function name(): string { return 'Tier3Stage'; }

    public function metadata(): StageMetadata
    {
        return new StageMetadata(
            name:           'Tier3Stage',
            reads:          [CameraIR::class, CompositionIR::class, Intent::class],
            writes:         [PromptIR::class],
            cost:           StageCost::cpu(12.0),
            description:    'CameraIR + CompositionIR + Intent → PromptIR: assembles all semantic clauses into structured prompt IR.',
            deterministic:  true,
            cacheable:      true,
            parallelizable: false,
            category:       'transform',
            capabilities:   [StageCapability::PURE, StageCapability::CACHEABLE, StageCapability::DETERMINISTIC, StageCapability::WRITE_IR],
        );
    }
}
