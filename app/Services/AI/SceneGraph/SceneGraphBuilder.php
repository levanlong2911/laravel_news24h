<?php

namespace App\Services\AI\SceneGraph;

use App\Services\AI\ScenePlanner\ScenePlanningResult;
use App\Services\AI\SceneGraph\Resolvers\CameraResolver;
use App\Services\AI\SceneGraph\Resolvers\ContinuityResolver;
use App\Services\AI\SceneGraph\Resolvers\CompositionResolver;
use App\Services\AI\SceneGraph\Resolvers\DirectorResolver;
use App\Services\AI\SceneGraph\Resolvers\EnvironmentResolver;
use App\Services\AI\SceneGraph\Resolvers\PhysicsResolver;
use App\Services\AI\SceneGraph\Resolvers\SemanticResolver;
use App\Services\AI\SceneGraph\Resolvers\SubjectResolver;
use App\Services\AI\SceneGraph\Resolvers\TimelineResolver;

/**
 * Thin orchestrator: calls each Resolver and assembles ShotSceneGraph.
 *
 * No conflict resolution lives here — each Resolver owns its domain.
 * Cross-linking between nodes (e.g., CompositionNode.subjectPosition →
 * CameraNode) is handled by calling CompositionResolver first and passing
 * the resulting CompositionNode into CameraResolver.
 *
 * Builder receives ScenePlanningResult (typed, no raw DSL).
 * Builder emits ShotSceneGraph (typed, ready for Validator then Renderer).
 */
final class SceneGraphBuilder
{
    public function build(ScenePlanningResult $result): ShotSceneGraph
    {
        $ctx = $result->context;

        // CompositionNode first — CameraResolver cross-links subjectPosition from it
        $composition = CompositionResolver::resolve($result->composition);
        $camera      = CameraResolver::resolve($ctx, $result->director, $composition);
        $subject     = SubjectResolver::resolve($ctx, $result->action);
        $physics     = PhysicsResolver::resolve($result->physics);
        $director    = DirectorResolver::resolve($result->director);
        $environment = EnvironmentResolver::resolve($result->continuity);
        $semantic    = SemanticResolver::resolve($result->semantic);
        $timeline    = TimelineResolver::resolve($result->timeline, $ctx->emotion);
        $continuity  = ContinuityResolver::resolve($result->continuity);

        return new ShotSceneGraph(
            shotId:      $ctx->shotId,
            sceneId:     $ctx->sceneId,
            shotOrder:   $ctx->shotOrder,
            dur:         $ctx->dur,
            lightCode:   $ctx->lightCode,
            sceneTitle:  $ctx->sceneTitle,
            camera:      $camera,
            subject:     $subject,
            physics:     $physics,
            composition: $composition,
            director:    $director,
            environment: $environment,
            semantic:    $semantic,
            timeline:    $timeline,
            continuity:  $continuity,
        );
    }
}
