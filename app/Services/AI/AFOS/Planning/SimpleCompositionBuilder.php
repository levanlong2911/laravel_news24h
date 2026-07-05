<?php

namespace App\Services\AI\AFOS\Planning;

use App\Services\AI\AFOS\Creative\CinematographyProfile;
use App\Services\AI\AFOS\Creative\DirectorProfile;
use App\Services\AI\AFOS\Ir\CompositionIR;
use App\Services\AI\AFOS\Ir\ShotGoalIR;
use App\Services\AI\AFOS\Passes\Config\CompositionPassConfig;
use App\Services\AI\AFOS\Types\CompositionRule;
use App\Services\AI\AFOS\Types\Emotion;
use App\Services\AI\AFOS\Types\EyeFlowDirection;
use App\Services\AI\AFOS\Types\GoalType;
use App\Services\AI\AFOS\Types\NarrativeFunction;
use App\Services\AI\AFOS\Types\NegativeSpaceDirection;

/**
 * SimpleCompositionBuilder — Phase A decision table.
 *
 * Maps (ShotGoalIR × DirectorProfile × CinematographyProfile) → CompositionIR.
 *
 * This is NOT a CSP solver — that is Phase B (CompositionSolver).
 * Replace this class with CompositionSolver in Phase B; interface is identical.
 *
 * AFOS Principle 3: "COMPOSITION DECIDES CAMERA."
 * CameraIR is ALWAYS derived from CompositionIR — never the reverse.
 *
 * Decision priority (highest wins):
 *   1. Director.symmetryWeight ≥ 0.80  → SYMMETRY
 *   2. Director.negativeSpaceWeight ≥ 0.65 + GoalType.REVEAL → GOLDEN_RATIO + big neg space
 *   3. Director.negativeSpaceWeight ≥ 0.65 → GOLDEN_RATIO
 *   4. GoalType.REVEAL + Director.revealWeight ≥ 0.70 → NEGATIVE_LEAD
 *   5. GoalType.FOLLOW → LEAD_ROOM
 *   6. Emotion POWER|TRIUMPH → CENTER_WEIGHT
 *   7. Default → RULE_OF_THIRDS
 */
final class SimpleCompositionBuilder
{
    public function __construct(
        private readonly CompositionPassConfig $config = new CompositionPassConfig(),
    ) {}

    public function build(
        ShotGoalIR            $shot,
        DirectorProfile       $director,
        CinematographyProfile $dp,
    ): CompositionIR {
        [$rule, $negDir, $negAmount] = $this->resolveLayout($shot, $director);
        [$frameX, $frameY]           = $this->primaryPosition($rule, $shot);
        $scale                        = $this->primaryScale($shot, $director);
        $eyeFlow                      = $this->eyeFlow($rule, $shot);
        [$fg, $mid, $bg]             = $this->depthLayers($shot, $dp);

        $rationale = sprintf(
            'Phase A decision: goalType=%s emotion=%s negSpace=%.2f symmetry=%.2f → rule=%s negDir=%s negAmt=%.2f',
            $shot->goalType->value,
            $shot->emotion->value,
            $director->negativeSpaceWeight,
            $director->symmetryWeight,
            $rule->value,
            $negDir->value,
            $negAmount,
        );

        return CompositionIR::fromArray([
            'shotId'                 => $shot->shotId,
            'primarySubjectEntity'   => $shot->goalTarget,
            'primarySubjectFrameX'   => $frameX,
            'primarySubjectFrameY'   => $frameY,
            'primarySubjectScale'    => $scale,
            'negativeSpaceDirection' => $negDir->value,
            'negativeSpaceAmount'    => $negAmount,
            'foregroundEntity'       => $fg,
            'midgroundEntity'        => $mid,
            'backgroundEntity'       => $bg,
            'compositionRule'        => $rule->value,
            'eyeFlowDirection'       => $eyeFlow->value,
            'decisionRationale'      => $rationale,
        ]);
    }

    // ── RULE 1: Director weights → composition layout ─────────────────────────

    private function resolveLayout(ShotGoalIR $shot, DirectorProfile $director): array
    {
        // Config bias is additive — Experience Engine adjusts this without touching the DirectorProfile.
        $negSpace = min(1.0, $director->negativeSpaceWeight + $this->config->negativeSpaceBias);

        // goldenRatioBias ≥ 0.5 forces golden ratio; Experience Engine learns optimal threshold per domain.
        if ($this->config->goldenRatioBias >= 0.5) {
            $negAmt = $shot->goalType === GoalType::REVEAL ? 0.42 : 0.38;
            return [CompositionRule::GOLDEN_RATIO, NegativeSpaceDirection::RIGHT, max($negAmt, round($negSpace * 0.6, 2))];
        }

        if ($director->symmetryWeight >= 0.80) {
            return [CompositionRule::SYMMETRY, NegativeSpaceDirection::BOTTOM, 0.18];
        }

        if ($negSpace >= 0.65 && $shot->goalType === GoalType::REVEAL) {
            return [CompositionRule::GOLDEN_RATIO, NegativeSpaceDirection::RIGHT, 0.42];
        }

        if ($negSpace >= 0.65) {
            return [CompositionRule::GOLDEN_RATIO, NegativeSpaceDirection::RIGHT, 0.38];
        }

        if ($shot->goalType === GoalType::REVEAL && $director->revealWeight >= 0.70) {
            return [CompositionRule::NEGATIVE_LEAD, NegativeSpaceDirection::LEFT, 0.45];
        }

        if ($shot->goalType === GoalType::FOLLOW) {
            return [CompositionRule::LEAD_ROOM, NegativeSpaceDirection::RIGHT, 0.35];
        }

        if (in_array($shot->emotion, [Emotion::POWER, Emotion::TRIUMPH], true)) {
            return [CompositionRule::CENTER_WEIGHT, NegativeSpaceDirection::BOTTOM, 0.22];
        }

        return [CompositionRule::RULE_OF_THIRDS, NegativeSpaceDirection::RIGHT, 0.32];
    }

    // ── RULE 2: Primary position from composition rule ────────────────────────

    private function primaryPosition(CompositionRule $rule, ShotGoalIR $shot): array
    {
        return match ($rule) {
            CompositionRule::GOLDEN_RATIO   => [0.382, 0.500],
            CompositionRule::SYMMETRY       => [0.500, 0.500],
            CompositionRule::LEAD_ROOM      => [0.333, 0.500],
            CompositionRule::NEGATIVE_LEAD  => [0.750, 0.500],
            CompositionRule::CENTER_WEIGHT  => [0.500, 0.500],
            CompositionRule::RULE_OF_THIRDS => match ($shot->narrativeFunction) {
                NarrativeFunction::ESTABLISH => [0.333, 0.667],
                NarrativeFunction::REVEAL    => [0.667, 0.333],
                default                       => [0.333, 0.500],
            },
        };
    }

    // ── RULE 3: Primary subject scale ────────────────────────────────────────

    private function primaryScale(ShotGoalIR $shot, DirectorProfile $director): float
    {
        $base = match (true) {
            $shot->energy >= 0.85 => 0.55,
            $shot->energy >= 0.65 => 0.42,
            $shot->energy >= 0.45 => 0.32,
            $shot->energy >= 0.25 => 0.24,
            default               => 0.16,
        };

        $goalMod = match ($shot->goalType) {
            GoalType::REVEAL    => -0.08,
            GoalType::ESTABLISH => -0.06,
            GoalType::DISCOVER  => -0.10,
            GoalType::FOLLOW    => +0.03,
            default             => 0.0,
        };

        // High negativeSpaceWeight director prefers smaller subject (more empty space)
        $directorMod = ($director->negativeSpaceWeight - 0.5) * -0.08;

        return round(max(0.10, min(0.70, $base + $goalMod + $directorMod)), 3);
    }

    // ── RULE 4: Eye flow direction ────────────────────────────────────────────

    private function eyeFlow(CompositionRule $rule, ShotGoalIR $shot): EyeFlowDirection
    {
        return match ($rule) {
            CompositionRule::SYMMETRY       => EyeFlowDirection::CENTRIPETAL,
            CompositionRule::LEAD_ROOM      => EyeFlowDirection::LEFT_TO_RIGHT,
            CompositionRule::NEGATIVE_LEAD  => EyeFlowDirection::RIGHT_TO_LEFT,
            CompositionRule::GOLDEN_RATIO   => EyeFlowDirection::DIAGONAL_TL_BR,
            default                          => match ($shot->narrativeFunction) {
                NarrativeFunction::REVEAL  => EyeFlowDirection::DIAGONAL_TL_BR,
                NarrativeFunction::RESOLVE => EyeFlowDirection::TOP_TO_BOTTOM,
                default                     => EyeFlowDirection::LEFT_TO_RIGHT,
            },
        };
    }

    // ── RULE 5: Depth layers from viewerShouldNotice ──────────────────────────

    private function depthLayers(ShotGoalIR $shot, CinematographyProfile $dp): array
    {
        $mid = $shot->goalTarget;
        $fg  = null;
        $bg  = null;

        if ($dp->depthLayersPreferred >= 2 && !empty($shot->viewerShouldNotice)) {
            $candidates = array_filter($shot->viewerShouldNotice, fn($e) => $e !== $shot->goalTarget);
            $fg = array_values($candidates)[0] ?? null;
        }

        if ($dp->depthLayersPreferred >= 3 && !empty($shot->viewerShouldIgnore)) {
            $bg = $shot->viewerShouldIgnore[0];
        }

        return [$fg, $mid, $bg];
    }
}
