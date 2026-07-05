<?php

namespace App\Services\AI\AFOS\Passes\Stages;

use App\Services\AI\AFOS\Backends\KlingBackend;
use App\Services\AI\AFOS\Ir\PromptIR;
use App\Services\AI\AFOS\Passes\Pipeline\CompilerStage;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineState;
use App\Services\AI\AFOS\Passes\Pipeline\StageCapability;
use App\Services\AI\AFOS\Passes\Pipeline\StageCost;
use App\Services\AI\AFOS\Passes\Pipeline\StageMetadata;

/**
 * BackendStage — PromptIR → compiled string.
 *
 * Pure serialization: no IR logic, no planner decisions.
 * Receives a fully-built PromptIR from Tier3Stage and writes the
 * backend's wire format into PipelineState::compiledPrompt.
 */
final class BackendStage implements CompilerStage
{
    public function run(PipelineState $state): PipelineState
    {
        $prompt = (new KlingBackend)->serialize($state->promptIR);
        $state->trace?->record('backend_prompt', [
            'prompt'  => $prompt,
            'length'  => strlen($prompt),
            'backend' => $state->backendId,
        ]);

        return $state->withCompiledPrompt($prompt);
    }

    public function name(): string { return 'BackendStage'; }

    public function metadata(): StageMetadata
    {
        return new StageMetadata(
            name:           'BackendStage',
            reads:          [PromptIR::class],
            writes:         ['compiledPrompt'],
            cost:           StageCost::cpu(2.0),
            description:    'PromptIR → string: pure serialization into backend wire format (Kling). No IR decisions made here.',
            deterministic:  true,
            cacheable:      true,
            parallelizable: false,
            category:       'serialization',
            capabilities:   [StageCapability::PURE, StageCapability::CACHEABLE, StageCapability::DETERMINISTIC, StageCapability::WRITE_IR],
        );
    }
}
