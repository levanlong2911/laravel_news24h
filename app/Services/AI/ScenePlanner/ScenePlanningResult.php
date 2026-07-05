<?php

namespace App\Services\AI\ScenePlanner;

use App\Services\AI\ScenePlanner\Plans\ActionPlan;
use App\Services\AI\ScenePlanner\Plans\CameraEnergyPlan;
use App\Services\AI\ScenePlanner\Plans\CinematicBeatPlan;
use App\Services\AI\ScenePlanner\Plans\CompositionPlan;
use App\Services\AI\ScenePlanner\Plans\ContinuityPlan;
use App\Services\AI\ScenePlanner\Plans\CameraMotivationPlan;
use App\Services\AI\ScenePlanner\Plans\CompositionEvolutionPlan;
use App\Services\AI\ScenePlanner\Plans\CuriosityPlan;
use App\Services\AI\ScenePlanner\Plans\EmotionArcPlan;
use App\Services\AI\ScenePlanner\Plans\DirectorPlan;
use App\Services\AI\ScenePlanner\Plans\EyeGuidancePlan;
use App\Services\AI\ScenePlanner\Plans\PhysicsPlan;
use App\Services\AI\ScenePlanner\Plans\RevealPlan;
use App\Services\AI\ScenePlanner\Plans\RhythmPlan;
use App\Services\AI\ScenePlanner\Plans\SemanticPlan;
use App\Services\AI\ScenePlanner\Plans\VisualContrastPlan;
use App\Services\AI\SceneGraph\Nodes\TimelineNode;

/**
 * Typed container for all planner artifacts for a single shot.
 *
 * ScenePlanner writes here. SceneGraphBuilder (via Resolvers) reads here.
 * No raw DSL array — all metadata is in ShotContext, all planner results
 * are in typed plan objects.
 *
 * Pipeline position:
 *   ScenePlanner::plan() → ScenePlanningResult → SceneGraphBuilder → ShotSceneGraph
 */
final class ScenePlanningResult
{
    public function __construct(
        /** Typed DSL metadata — replaces the former `array $dsl` field */
        public readonly ShotContext      $context,
        public readonly ActionPlan       $action,
        public readonly PhysicsPlan      $physics,
        public readonly DirectorPlan     $director,
        public readonly CompositionPlan  $composition,
        public readonly ContinuityPlan   $continuity,
        public readonly SemanticPlan     $semantic,
        /** Velocity-enhanced timeline — PhaseNode[] driven by CameraEnergyPlanner */
        public readonly TimelineNode      $timeline,
        /** 4-beat cinematic arc: Hook → Escalation → Reveal → Payoff */
        public readonly CinematicBeatPlan $cinematicBeat,
        /** Velocity curves injected into each beat's camera directive */
        public readonly CameraEnergyPlan  $cameraEnergy,
        /** Timing variation profile (action_burst, aerial, suspense, …) */
        public readonly RhythmPlan        $rhythm,
        /** Per-beat information state (concealed → partial → revealed → full) */
        public readonly CuriosityPlan     $curiosity,
        /** Reveal mechanism at reveal beat (through_cloud, focus_pull, …) */
        public readonly RevealPlan              $reveal,
        /** Per-beat depth field evolution (foreground/mid/background layers shift across arc) */
        public readonly CompositionEvolutionPlan $compositionEvolution,
        /** Per-beat eye anchor chain (eyes → hands → contact → scale) */
        public readonly EyeGuidancePlan          $eyeGuidance,
        /** Per-beat brightness + temperature contrast (dark cool → warm bright alternation) */
        public readonly VisualContrastPlan        $visualContrast,
        /** Per-beat emotional state (wonder → recognition → declaration → awe) */
        public readonly EmotionArcPlan            $emotionArc,
        /** Per-beat camera motivation — the WHY behind each camera move */
        public readonly CameraMotivationPlan      $cameraMotivation,
    ) {}

    public function shotId(): string
    {
        return $this->context->shotId;
    }
}
