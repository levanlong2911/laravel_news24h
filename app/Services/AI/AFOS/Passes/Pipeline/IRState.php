<?php

namespace App\Services\AI\AFOS\Passes\Pipeline;

use App\Services\AI\AFOS\Ir\CameraIR;
use App\Services\AI\AFOS\Ir\CompositionIR;
use App\Services\AI\AFOS\Ir\PromptIR;
use App\Services\AI\AFOS\Ir\Temporal\FrozenTemporalGraph;
use App\Services\AI\AFOS\Ir\Temporal\TemporalGraph;

/**
 * IRState — the progressive IR artifact set produced as stages execute.
 *
 * Immutable value object. Each stage creates a new IRState by calling one of
 * the withXxx() methods. The old IRState is discarded.
 *
 * Lifecycle:
 *   IRState::empty()                          ← initial (all null)
 *   → withComposition($c)                     ← Tier1Stage
 *   → withGraph($g), withGraph($g+camera)     ← MotionBeatStage, CameraArcStage
 *   → withCamera($c)                          ← Tier2Stage
 *   → sealed($frozen)                         ← FreezeStage: graph→null, frozenGraph set
 *   → withPromptIR($p)                        ← Tier3Stage
 *   → withCompiledPrompt($s)                  ← BackendStage
 *
 * The sealed() method is the single point where the mutable TemporalGraph is released
 * and replaced with the FrozenTemporalGraph. Single ownership: after sealed(), no
 * downstream IRState carries the mutable graph.
 */
final class IRState
{
    public function __construct(
        public readonly ?CompositionIR       $composition    = null,
        public readonly ?CameraIR            $camera         = null,
        public readonly ?PromptIR            $promptIR       = null,
        public readonly ?string              $compiledPrompt = null,
        public readonly ?TemporalGraph       $graph          = null,
        public readonly ?FrozenTemporalGraph $frozenGraph    = null,
    ) {}

    public static function empty(): self
    {
        return new self();
    }

    // ── Immutable updates ─────────────────────────────────────────────────────

    public function withComposition(CompositionIR $composition): self
    {
        return new self(
            composition:    $composition,
            camera:         $this->camera,
            promptIR:       $this->promptIR,
            compiledPrompt: $this->compiledPrompt,
            graph:          $this->graph,
            frozenGraph:    $this->frozenGraph,
        );
    }

    public function withCamera(CameraIR $camera): self
    {
        return new self(
            composition:    $this->composition,
            camera:         $camera,
            promptIR:       $this->promptIR,
            compiledPrompt: $this->compiledPrompt,
            graph:          $this->graph,
            frozenGraph:    $this->frozenGraph,
        );
    }

    public function withPromptIR(PromptIR $promptIR): self
    {
        return new self(
            composition:    $this->composition,
            camera:         $this->camera,
            promptIR:       $promptIR,
            compiledPrompt: $this->compiledPrompt,
            graph:          $this->graph,
            frozenGraph:    $this->frozenGraph,
        );
    }

    public function withCompiledPrompt(string $compiledPrompt): self
    {
        return new self(
            composition:    $this->composition,
            camera:         $this->camera,
            promptIR:       $this->promptIR,
            compiledPrompt: $compiledPrompt,
            graph:          $this->graph,
            frozenGraph:    $this->frozenGraph,
        );
    }

    public function withGraph(TemporalGraph $graph): self
    {
        return new self(
            composition:    $this->composition,
            camera:         $this->camera,
            promptIR:       $this->promptIR,
            compiledPrompt: $this->compiledPrompt,
            graph:          $graph,
            frozenGraph:    $this->frozenGraph,
        );
    }

    public function withFrozenGraph(FrozenTemporalGraph $frozenGraph): self
    {
        return new self(
            composition:    $this->composition,
            camera:         $this->camera,
            promptIR:       $this->promptIR,
            compiledPrompt: $this->compiledPrompt,
            graph:          $this->graph,
            frozenGraph:    $frozenGraph,
        );
    }

    /**
     * BUILD→FREEZE ownership transfer.
     *
     * Sets graph = null (releases the mutable builder) and installs $frozenGraph.
     * After this call, no downstream IRState carries the mutable TemporalGraph.
     *
     * @internal Called only by PipelineState::sealed(), which is itself @internal
     *           to FreezeStage. Two-layer guard: IRState::sealed() should never
     *           be called directly outside the PipelineState → IRState chain.
     */
    public function sealed(FrozenTemporalGraph $frozenGraph): self
    {
        return new self(
            composition:    $this->composition,
            camera:         $this->camera,
            promptIR:       $this->promptIR,
            compiledPrompt: $this->compiledPrompt,
            graph:          null,
            frozenGraph:    $frozenGraph,
        );
    }
}
