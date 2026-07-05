<?php

namespace App\Services\AI\AFOS\Ir;

use App\Services\AI\AFOS\Creative\Intent;

/**
 * SemanticState — the backend-agnostic semantic description of a shot.
 *
 * Contains only typed, enum-bounded fields that determine what a video will
 * look like regardless of which backend renders it. When Veo or Runway is
 * added, their PromptArtifacts are derived from the same SemanticState.
 *
 * No prose text. No float weights. Only classification values that a human
 * and a CV model can both reason about.
 */
final class SemanticState
{
    public function __construct(
        // ── Shot intent ───────────────────────────────────────────────────────
        public readonly string $entityId,           // ShotGoalIR.goalTarget
        public readonly string $goalType,           // ShotGoalIR.goalType.value
        public readonly string $emotion,            // ShotGoalIR.emotion.value
        public readonly string $narrativeFunction,  // ShotGoalIR.narrativeFunction.value

        // ── Camera ────────────────────────────────────────────────────────────
        public readonly string $cameraMovement,     // CameraIR.movementType.value
        public readonly string $cameraStartHeight,  // CameraIR.startHeight.value
        public readonly int    $focalLengthMm,      // CameraIR.focalLengthMm
        public readonly string $framing,            // CameraIR.framing.value

        // ── Composition ───────────────────────────────────────────────────────
        public readonly string $compositionRule,    // CompositionIR.compositionRule.value
        public readonly string $negativeSpaceDir,   // CompositionIR.negativeSpaceDirection.value

        // ── Pacing ────────────────────────────────────────────────────────────
        public readonly string $tempo,              // Intent.tempo.value
    ) {}

    public static function build(
        ShotGoalIR    $shot,
        CameraIR      $camera,
        CompositionIR $composition,
        Intent        $intent,
    ): self {
        return new self(
            entityId:           $shot->goalTarget,
            goalType:           $shot->goalType->value,
            emotion:            $shot->emotion->value,
            narrativeFunction:  $shot->narrativeFunction->value,
            cameraMovement:     $camera->movementType->value,
            cameraStartHeight:  $camera->startHeight->value,
            focalLengthMm:      $camera->focalLengthMm,
            framing:            $camera->framing->value,
            compositionRule:    $composition->compositionRule->value,
            negativeSpaceDir:   $composition->negativeSpaceDirection->value,
            tempo:              $intent->tempo->value,
        );
    }

    public function toArray(): array
    {
        return [
            'entity_id'           => $this->entityId,
            'goal_type'           => $this->goalType,
            'emotion'             => $this->emotion,
            'narrative_function'  => $this->narrativeFunction,
            'camera_movement'     => $this->cameraMovement,
            'camera_start_height' => $this->cameraStartHeight,
            'focal_length_mm'     => $this->focalLengthMm,
            'framing'             => $this->framing,
            'composition_rule'    => $this->compositionRule,
            'negative_space_dir'  => $this->negativeSpaceDir,
            'tempo'               => $this->tempo,
        ];
    }
}
