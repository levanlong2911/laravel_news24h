<?php

namespace App\Services\AI\SceneGraph\Resolvers;

use App\Services\AI\ScenePlanner\Plans\DirectorPlan;
use App\Services\AI\ScenePlanner\ShotContext;
use App\Services\AI\SceneGraph\Nodes\CameraNode;
use App\Services\AI\SceneGraph\Nodes\CompositionNode;

/**
 * Resolves camera parameters into a CameraNode.
 *
 * Conflict rule:
 *   move + camType + motionLevel + lensCode → from ShotContext (DSL is authoritative)
 *   height + stabilization                  → DirectorPlan wins
 *   subjectPosition                         → cross-linked from CompositionNode
 */
final class CameraResolver
{
    public static function resolve(
        ShotContext    $ctx,
        DirectorPlan   $director,
        CompositionNode $composition,
    ): CameraNode {
        return new CameraNode(
            move:            $ctx->cameraMove,
            camType:         $ctx->camType,
            lensCode:        $ctx->lensCode,
            height:          $director->cameraHeight,
            motionLevel:     $ctx->motionLevel,
            stabilization:   $director->stabilization,
            subjectPosition: $composition->subjectPosition,
        );
    }
}
