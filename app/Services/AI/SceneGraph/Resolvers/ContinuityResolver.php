<?php

namespace App\Services\AI\SceneGraph\Resolvers;

use App\Services\AI\ScenePlanner\Plans\ContinuityPlan;
use App\Services\AI\SceneGraph\Nodes\CameraContinuityNode;
use App\Services\AI\SceneGraph\Nodes\ContinuityConstraints;
use App\Services\AI\SceneGraph\Nodes\ContinuityNode;
use App\Services\AI\SceneGraph\Nodes\EnvironmentNode;

/**
 * Resolves ContinuityPlan into a typed ContinuityNode.
 *
 * IdentityNode and DynamicStateNode are already typed in ContinuityPlan.
 * This resolver converts the remaining array fields:
 *   environment → EnvironmentNode (shared type with EnvironmentResolver output)
 *   camera      → CameraContinuityNode
 *   constraints → ContinuityConstraints
 */
final class ContinuityResolver
{
    public static function resolve(ContinuityPlan $plan): ContinuityNode
    {
        return new ContinuityNode(
            identity:      $plan->identity,
            dynamicState:  $plan->dynamicState,
            environment:   EnvironmentNode::from($plan->environment),
            camera:        CameraContinuityNode::from($plan->camera),
            constraints:   ContinuityConstraints::from($plan->constraints),
            previousState: $plan->previousState,
        );
    }
}
