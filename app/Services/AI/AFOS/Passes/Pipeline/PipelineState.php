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
use App\Services\AI\AFOS\Observability\TraceCollector;

/**
 * PipelineState — immutable carrier for all compiler inputs and IR artifacts.
 *
 * Stages receive a PipelineState, do their work, and return a new PipelineState
 * with one artifact added. The DiagnosticBag is intentionally mutable and shared
 * across all copies — diagnostics accumulate in place.
 *
 * Lifecycle:
 *   Initial state (inputs only)
 *   → after Tier1Stage: $composition is set
 *   → after Tier2Stage: $camera is set
 *   → after Tier3Stage: $promptIR is set
 *   → after BackendStage: $compiledPrompt is set
 */
final class PipelineState
{
    public function __construct(
        // ── Compiler inputs (always set) ────────────────────────────────────
        public readonly ShotGoalIR            $shot,
        public readonly DirectorProfile       $director,
        public readonly CinematographyProfile $dp,
        public readonly Intent                $intent,
        public readonly DiagnosticBag         $bag,
        public readonly string                $backendId     = 'kling',
        public readonly ?TraceCollector       $trace         = null,

        // ── IR artifacts (set progressively by stages) ───────────────────────
        public readonly ?CompositionIR        $composition   = null,
        public readonly ?CameraIR             $camera        = null,
        public readonly ?PromptIR             $promptIR      = null,
        public readonly ?string               $compiledPrompt = null,
    ) {}

    /**
     * Transplant a DiagnosticBag onto a cached state.
     * Used by CacheManager to reattach the live bag when restoring from cache.
     */
    public function withBag(DiagnosticBag $bag): self
    {
        return new self(
            shot:           $this->shot,
            director:       $this->director,
            dp:             $this->dp,
            intent:         $this->intent,
            bag:            $bag,
            backendId:      $this->backendId,
            trace:          $this->trace,
            composition:    $this->composition,
            camera:         $this->camera,
            promptIR:       $this->promptIR,
            compiledPrompt: $this->compiledPrompt,
        );
    }

    public function withComposition(CompositionIR $composition): self
    {
        return new self(
            shot:           $this->shot,
            director:       $this->director,
            dp:             $this->dp,
            intent:         $this->intent,
            bag:            $this->bag,
            backendId:      $this->backendId,
            trace:          $this->trace,
            composition:    $composition,
            camera:         $this->camera,
            promptIR:       $this->promptIR,
            compiledPrompt: $this->compiledPrompt,
        );
    }

    public function withCamera(CameraIR $camera): self
    {
        return new self(
            shot:           $this->shot,
            director:       $this->director,
            dp:             $this->dp,
            intent:         $this->intent,
            bag:            $this->bag,
            backendId:      $this->backendId,
            trace:          $this->trace,
            composition:    $this->composition,
            camera:         $camera,
            promptIR:       $this->promptIR,
            compiledPrompt: $this->compiledPrompt,
        );
    }

    public function withPromptIR(PromptIR $promptIR): self
    {
        return new self(
            shot:           $this->shot,
            director:       $this->director,
            dp:             $this->dp,
            intent:         $this->intent,
            bag:            $this->bag,
            backendId:      $this->backendId,
            trace:          $this->trace,
            composition:    $this->composition,
            camera:         $this->camera,
            promptIR:       $promptIR,
            compiledPrompt: $this->compiledPrompt,
        );
    }

    public function withCompiledPrompt(string $compiledPrompt): self
    {
        return new self(
            shot:           $this->shot,
            director:       $this->director,
            dp:             $this->dp,
            intent:         $this->intent,
            bag:            $this->bag,
            backendId:      $this->backendId,
            trace:          $this->trace,
            composition:    $this->composition,
            camera:         $this->camera,
            promptIR:       $this->promptIR,
            compiledPrompt: $compiledPrompt,
        );
    }
}
