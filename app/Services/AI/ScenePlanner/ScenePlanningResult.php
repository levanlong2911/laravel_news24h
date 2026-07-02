<?php

namespace App\Services\AI\ScenePlanner;

use App\Services\AI\ScenePlanner\Plans\ActionPlan;
use App\Services\AI\ScenePlanner\Plans\CompositionPlan;
use App\Services\AI\ScenePlanner\Plans\ContinuityPlan;
use App\Services\AI\ScenePlanner\Plans\DirectorPlan;
use App\Services\AI\ScenePlanner\Plans\PhysicsPlan;
use App\Services\AI\ScenePlanner\Plans\SemanticPlan;
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
        public readonly ShotContext    $context,
        public readonly ActionPlan     $action,
        public readonly PhysicsPlan    $physics,
        public readonly DirectorPlan   $director,
        public readonly CompositionPlan $composition,
        public readonly ContinuityPlan  $continuity,
        public readonly SemanticPlan    $semantic,
        /** Enriched timeline — PhaseNode[] wrapped in TimelineNode */
        public readonly TimelineNode    $timeline,
    ) {}

    public function shotId(): string
    {
        return $this->context->shotId;
    }
}
