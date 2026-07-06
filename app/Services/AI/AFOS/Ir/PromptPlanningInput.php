<?php

namespace App\Services\AI\AFOS\Ir;

use App\Services\AI\AFOS\Creative\Intent;
use App\Services\AI\AFOS\Ir\Temporal\TrackCollectionView;

/**
 * PromptPlanningInput — typed input contract for the LOWER phase (CameraIR + CompositionIR → PromptIR).
 *
 * Tier3Stage maps PipelineState → PromptPlanningInput before calling plan().
 * plan() is then pure: zero knowledge of PipelineState or compiler lifecycle.
 *
 * Parallel to BackendInput (EMIT phase) — same adapter/domain split:
 *   run(PipelineState) → [map] → PromptPlanningInput → plan(PromptPlanningInput): PromptIR
 *
 * Future fields (styleOverride, budgetConstraint, locale) extend here
 * without touching Tier3Stage::run() or any upstream stage.
 */
final class PromptPlanningInput implements StageInput
{
    public function __construct(
        /** The lowered camera description produced by Tier2Stage + CameraArcStage. */
        public readonly CameraIR    $camera,

        /** The composition blueprint produced by Tier1Stage. */
        public readonly CompositionIR $composition,

        /** Creative intent — emotion, narrative, tempo, viewer experience. */
        public readonly Intent        $intent,

        /**
         * Optional frozen temporal graph. Provides time-coded motion beats and
         * camera arcs if MotionBeatStage/CameraArcStage ran before FreezeStage.
         * Null when no temporal planning was performed (legacy single-shot path).
         */
        public readonly ?TrackCollectionView $temporal = null,
    ) {}
}
