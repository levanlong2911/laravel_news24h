<?php

namespace App\Services\AI\AFOS\Passes\Pipeline;

use App\Services\AI\AFOS\Compiler\Diagnostics\DiagnosticBag;
use App\Services\AI\AFOS\Creative\CinematographyProfile;
use App\Services\AI\AFOS\Creative\DirectorProfile;
use App\Services\AI\AFOS\Creative\Intent;
use App\Services\AI\AFOS\Ir\CameraIR;
use App\Services\AI\AFOS\Ir\CompositionIR;
use App\Services\AI\AFOS\Ir\PromptIR;
use App\Services\AI\AFOS\Ir\ShotGoalIR;
use App\Services\AI\AFOS\Ir\Temporal\FrozenTemporalGraph;
use App\Services\AI\AFOS\Ir\Temporal\TemporalGraph;
use App\Services\AI\AFOS\Observability\TraceCollector;

/**
 * PipelineState — immutable aggregate of three compiler concerns.
 *
 * Internal structure:
 *   inputs  PipelineInputs — static creative brief (never changes)
 *   bag     DiagnosticBag  — shared mutable accumulator for errors/warnings
 *   ir      IRState        — progressive IR artifacts written by stages
 *   phase   CompilerPhase  — lifecycle position; advanced by barrier stages
 *
 * Delegation readonly properties (shot, director, camera, frozenGraph, …) expose
 * the sub-object fields at the top level so that existing stage code needs no changes.
 *
 * Phase lifecycle:
 *   BUILD  → initial; MotionBeatStage, CameraArcStage write to TemporalGraph
 *   FREEZE → entered by sealed(); FreezeStage is the only legal caller
 *   LOWER  → entered by Tier3Stage after asserting FREEZE
 *   EMIT   → (future) entered by BackendStage
 *
 * Transition invariant: withPhase() and sealed() both call CompilerPhase::transitionTo(),
 * which throws LogicException on illegal jumps (e.g. BUILD → EMIT).
 */
final class PipelineState
{
    // ── Grouped sub-objects ───────────────────────────────────────────────────

    /** Static creative brief — fixed for the lifetime of a compile run. */
    public readonly PipelineInputs $inputs;

    /** Shared mutable diagnostic accumulator — all copies share the same instance. */
    public readonly DiagnosticBag  $bag;

    /** Progressive IR artifacts — a new IRState is created each time a stage writes. */
    public readonly IRState        $ir;

    /** Compiler lifecycle position — advanced only by barrier stages (sealed) or withPhase(). */
    public readonly CompilerPhase  $phase;

    // ── Delegation properties (PipelineInputs) ────────────────────────────────

    public readonly ShotGoalIR            $shot;
    public readonly DirectorProfile       $director;
    public readonly CinematographyProfile $dp;
    public readonly Intent                $intent;
    public readonly string                $backendId;
    public readonly ?TraceCollector       $trace;

    // ── Delegation properties (IRState) ───────────────────────────────────────

    public readonly ?CompositionIR        $composition;
    public readonly ?CameraIR             $camera;
    public readonly ?PromptIR             $promptIR;
    public readonly ?string               $compiledPrompt;
    public readonly ?TemporalGraph        $graph;
    public readonly ?FrozenTemporalGraph  $frozenGraph;

    // ── Constructor ───────────────────────────────────────────────────────────

    public function __construct(
        PipelineInputs $inputs,
        DiagnosticBag  $bag,
        IRState        $ir    = new IRState(),
        CompilerPhase  $phase = CompilerPhase::BUILD,
    ) {
        $this->inputs = $inputs;
        $this->bag    = $bag;
        $this->ir     = $ir;
        $this->phase  = $phase;

        // Delegate from inputs so stages use $state->shot, $state->director, etc.
        $this->shot      = $inputs->shot;
        $this->director  = $inputs->director;
        $this->dp        = $inputs->dp;
        $this->intent    = $inputs->intent;
        $this->backendId = $inputs->backendId;
        $this->trace     = $inputs->trace;

        // Delegate from ir so stages use $state->camera, $state->frozenGraph, etc.
        $this->composition    = $ir->composition;
        $this->camera         = $ir->camera;
        $this->promptIR       = $ir->promptIR;
        $this->compiledPrompt = $ir->compiledPrompt;
        $this->graph          = $ir->graph;
        $this->frozenGraph    = $ir->frozenGraph;
    }

    // ── Phase management ──────────────────────────────────────────────────────

    /**
     * Assert the pipeline is in the expected phase; throw if not.
     *
     * Stages call this at the top of run() to fail immediately if placed
     * in the wrong position (e.g. Tier3Stage before FreezeStage).
     */
    public function requirePhase(CompilerPhase $expected): void
    {
        if ($this->phase !== $expected) {
            throw new \LogicException(sprintf(
                'Phase assertion failed: expected %s but pipeline is in %s phase.',
                $expected->value, $this->phase->value,
            ));
        }
    }

    /**
     * BUILD → FREEZE lifecycle boundary — called by FreezeStage only.
     *
     * Delegates graph sealing to IRState::sealed() (graph→null, frozenGraph set),
     * and advances the compiler phase to FREEZE via the legal-transition check.
     *
     * @internal Only FreezeStage should call this. Calling sealed() from any other
     *           stage bypasses the single-ownership invariant and may produce a
     *           pipeline that has no FreezeStage barrier in its execution plan.
     *           Static analysis (PHPStan + phpstan-strict-rules) enforces this.
     */
    public function sealed(FrozenTemporalGraph $frozenGraph): self
    {
        return new self(
            inputs: $this->inputs,
            bag:    $this->bag,
            ir:     $this->ir->sealed($frozenGraph),
            phase:  $this->phase->transitionTo(CompilerPhase::FREEZE),
        );
    }

    // ── Immutable update methods ──────────────────────────────────────────────

    /**
     * Advance the compiler phase. Validates the transition via CompilerPhase::transitionTo()
     * and throws LogicException on illegal jumps (e.g. FREEZE → EMIT, skipping LOWER).
     */
    public function withPhase(CompilerPhase $phase): self
    {
        return new self(
            inputs: $this->inputs,
            bag:    $this->bag,
            ir:     $this->ir,
            phase:  $this->phase->transitionTo($phase),
        );
    }

    public function withBag(DiagnosticBag $bag): self
    {
        return new self(inputs: $this->inputs, bag: $bag, ir: $this->ir, phase: $this->phase);
    }

    public function withComposition(CompositionIR $composition): self
    {
        return new self(
            inputs: $this->inputs,
            bag:    $this->bag,
            ir:     $this->ir->withComposition($composition),
            phase:  $this->phase,
        );
    }

    public function withCamera(CameraIR $camera): self
    {
        return new self(
            inputs: $this->inputs,
            bag:    $this->bag,
            ir:     $this->ir->withCamera($camera),
            phase:  $this->phase,
        );
    }

    public function withPromptIR(PromptIR $promptIR): self
    {
        return new self(
            inputs: $this->inputs,
            bag:    $this->bag,
            ir:     $this->ir->withPromptIR($promptIR),
            phase:  $this->phase,
        );
    }

    public function withCompiledPrompt(string $compiledPrompt): self
    {
        return new self(
            inputs: $this->inputs,
            bag:    $this->bag,
            ir:     $this->ir->withCompiledPrompt($compiledPrompt),
            phase:  $this->phase,
        );
    }

    public function withGraph(TemporalGraph $graph): self
    {
        return new self(
            inputs: $this->inputs,
            bag:    $this->bag,
            ir:     $this->ir->withGraph($graph),
            phase:  $this->phase,
        );
    }

    public function withFrozenGraph(FrozenTemporalGraph $frozenGraph): self
    {
        return new self(
            inputs: $this->inputs,
            bag:    $this->bag,
            ir:     $this->ir->withFrozenGraph($frozenGraph),
            phase:  $this->phase,
        );
    }
}
