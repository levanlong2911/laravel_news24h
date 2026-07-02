<?php

namespace App\Services\AI\SceneGraph\Resolvers;

use App\Services\AI\ScenePlanner\Plans\DirectorPlan;
use App\Services\AI\SceneGraph\Nodes\DirectorNode;

/**
 * Resolves director plan into a DirectorNode.
 *
 * motionBlur label → normalized float:
 *   'minimal' → 0.1   'natural' → 0.3   'high' → 0.7   anything else → 0.3
 */
final class DirectorResolver
{
    private const BLUR_MAP = [
        'minimal' => 0.1,
        'natural' => 0.3,
        'high'    => 0.7,
        'none'    => 0.0,
    ];

    public static function resolve(DirectorPlan $plan): DirectorNode
    {
        $motionBlur = self::BLUR_MAP[$plan->motionBlurLabel] ?? 0.3;

        return new DirectorNode(
            pacing:       $plan->pacing,
            framing:      $plan->framing,
            shotPriority: $plan->shotPriority,
            motionBlur:   $motionBlur,
            rackFocus:    $plan->rackFocus,
            acceleration: $plan->acceleration,
        );
    }
}
