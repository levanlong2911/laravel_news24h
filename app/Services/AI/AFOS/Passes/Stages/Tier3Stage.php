<?php

namespace App\Services\AI\AFOS\Passes\Stages;

use App\Services\AI\AFOS\Creative\Intent;
use App\Services\AI\AFOS\Ir\CameraIR;
use App\Services\AI\AFOS\Ir\CompositionIR;
use App\Services\AI\AFOS\Ir\PromptIR;
use App\Services\AI\AFOS\Ir\PromptPlanningInput;
use App\Services\AI\AFOS\Ir\Temporal\FrozenTemporalGraph;
use App\Services\AI\AFOS\Passes\Pipeline\CompilerPhase;
use App\Services\AI\AFOS\Passes\Pipeline\CompilerStage;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineState;
use App\Services\AI\AFOS\Passes\Pipeline\StageCapability;
use App\Services\AI\AFOS\Passes\Pipeline\StageCost;
use App\Services\AI\AFOS\Passes\Pipeline\StageMetadata;
use App\Services\AI\AFOS\Passes\Prompt\PlannerResolver;

/**
 * Tier3Stage — LOWER phase: CameraIR + CompositionIR + Intent + FrozenTemporalGraph → PromptIR.
 *
 * Adapter / domain split (symmetric with BackendStage):
 *   run()  — pipeline adapter: phase assertion, PipelineState → PromptPlanningInput, trace
 *   plan() — pure domain: delegates to PlannerResolver → planner for this backendId
 *
 * Tier3Stage does NOT know which planner is active. It only knows PlannerResolver.
 * PlannerResolver resolves $backendId → PlannerRegistry → PromptPlannerInterface → plan().
 *
 * Extension: register a new planner in PlannerRegistry. Zero changes here.
 *
 * Symmetric with BackendStage:
 *   BackendStage → BackendEmitter → BackendRegistry → KlingBackend
 *   Tier3Stage   → PlannerResolver → PlannerRegistry → KlingPromptPlanningPass
 */
final class Tier3Stage implements CompilerStage
{
    private readonly PlannerResolver $resolver;

    public function __construct(?PlannerResolver $resolver = null)
    {
        $this->resolver = $resolver ?? PlannerResolver::withDefaults();
    }

    public function run(PipelineState $state): PipelineState
    {
        $state->requirePhase(CompilerPhase::FREEZE);

        $input = new PromptPlanningInput(
            camera:      $state->camera ?? throw new \LogicException(
                'Tier3Stage requires CameraIR — Tier2Stage has not run.'
            ),
            composition: $state->composition ?? throw new \LogicException(
                'Tier3Stage requires CompositionIR — Tier1Stage has not run.'
            ),
            intent:      $state->intent,
            temporal:    $state->frozenGraph,
        );

        $promptIR = $this->plan($input, $state->backendId);

        $state->trace?->record('prompt_ir', $promptIR->toArray());
        $state->trace?->recordPass(
            $this->resolver->plannerName($state->backendId),
            ['camera_ir' => $input->camera->toArray(), 'composition_ir' => $input->composition->toArray()],
            $promptIR->toArray(),
            [],
        );

        return $state->withPromptIR($promptIR)->withPhase(CompilerPhase::LOWER);
    }

    /**
     * Pure planning — no PipelineState knowledge.
     * PlannerResolver resolves the planner and calls plan(PromptPlanningInput).
     */
    private function plan(PromptPlanningInput $input, string $backendId): PromptIR
    {
        return $this->resolver->plan($input, $backendId);
    }

    public function name(): string { return 'Tier3Stage'; }

    public function metadata(): StageMetadata
    {
        return new StageMetadata(
            name:           'Tier3Stage',
            reads:          [CameraIR::class, CompositionIR::class, Intent::class, FrozenTemporalGraph::class],
            writes:         [PromptIR::class],
            cost:           StageCost::cpu(12.0),
            description:    'CameraIR + CompositionIR + Intent + FrozenTemporalGraph → PromptIR: dispatches to PlannerResolver which routes to the active backend planner.',
            deterministic:  true,
            cacheable:      true,
            parallelizable: false,
            category:       'transform',
            capabilities:   [StageCapability::PURE, StageCapability::CACHEABLE, StageCapability::DETERMINISTIC, StageCapability::WRITE_IR],
            phase:          CompilerPhase::LOWER,
        );
    }
}
