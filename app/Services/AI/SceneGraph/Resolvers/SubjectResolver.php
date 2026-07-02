<?php

namespace App\Services\AI\SceneGraph\Resolvers;

use App\Services\AI\ScenePlanner\Plans\ActionPlan;
use App\Services\AI\ScenePlanner\ShotContext;
use App\Services\AI\SceneGraph\Nodes\SubjectNode;

/**
 * Resolves subject fields into a SubjectNode.
 */
final class SubjectResolver
{
    public static function resolve(ShotContext $ctx, ActionPlan $action): SubjectNode
    {
        return new SubjectNode(
            actor:        $action->primaryActor ?: $ctx->sceneTitle,
            actionType:   $action->actionType,
            objectInHand: $action->objectInHand,
        );
    }
}
