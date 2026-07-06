<?php

namespace App\Services\AI\AFOS\Passes\Stages;

use App\Services\AI\AFOS\Backends\BackendEmitter;
use App\Services\AI\AFOS\Ir\BackendInput;
use App\Services\AI\AFOS\Ir\PromptIR;
use App\Services\AI\AFOS\Passes\Pipeline\CompilerPhase;
use App\Services\AI\AFOS\Passes\Pipeline\CompilerStage;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineState;
use App\Services\AI\AFOS\Passes\Pipeline\StageCapability;
use App\Services\AI\AFOS\Passes\Pipeline\StageCost;
use App\Services\AI\AFOS\Passes\Pipeline\StageMetadata;

/**
 * BackendStage — EMIT phase: PromptIR → compiled string.
 *
 * Adapter / domain split:
 *   run()  — pipeline adapter: phase assertion, PipelineState → BackendInput mapping, trace
 *   emit() — pure domain: BackendInput → string via BackendEmitter (no PipelineState knowledge)
 *
 * BackendStage does NOT know which backend is active. It only knows BackendEmitter.
 * BackendEmitter resolves $input->backendId → BackendInterface → serialize().
 *
 * Extension: register a new backend in BackendRegistry. Zero changes here.
 *
 * LLVM analogue:
 *   run()     ↔ pass manager driver
 *   emit()    ↔ TargetMachine::emit()
 *   emitter   ↔ TargetMachine
 */
final class BackendStage implements CompilerStage
{
    private readonly BackendEmitter $emitter;

    public function __construct(?BackendEmitter $emitter = null)
    {
        $this->emitter = $emitter ?? BackendEmitter::withDefaults();
    }

    public function run(PipelineState $state): PipelineState
    {
        $state->requirePhase(CompilerPhase::LOWER);

        $input = new BackendInput(
            prompt:    $state->promptIR ?? throw new \LogicException(
                'BackendStage requires PromptIR — Tier3Stage has not run.'
            ),
            backendId: $state->backendId,
        );

        $compiled = $this->emit($input);

        $state->trace?->record('backend_prompt', [
            'prompt'  => $compiled,
            'length'  => strlen($compiled),
            'backend' => $input->backendId,
        ]);

        return $state->withCompiledPrompt($compiled);
    }

    /**
     * Pure emission — no PipelineState knowledge.
     * BackendEmitter resolves the backend and serializes the PromptIR.
     */
    private function emit(BackendInput $input): string
    {
        return $this->emitter->emit($input);
    }

    public function name(): string { return 'BackendStage'; }

    public function metadata(): StageMetadata
    {
        return new StageMetadata(
            name:           'BackendStage',
            reads:          [PromptIR::class],
            writes:         ['compiledPrompt'],
            cost:           StageCost::cpu(2.0),
            description:    'PromptIR → string: dispatches to BackendEmitter which resolves the active backend and serializes. Zero knowledge of which backend runs.',
            deterministic:  true,
            cacheable:      true,
            parallelizable: false,
            category:       'serialization',
            phase:          CompilerPhase::EMIT,
            capabilities:   [StageCapability::PURE, StageCapability::CACHEABLE, StageCapability::DETERMINISTIC, StageCapability::WRITE_IR],
        );
    }
}
