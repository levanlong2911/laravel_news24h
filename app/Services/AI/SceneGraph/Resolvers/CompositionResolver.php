<?php

namespace App\Services\AI\SceneGraph\Resolvers;

use App\Services\AI\ScenePlanner\Plans\CompositionPlan;
use App\Services\AI\SceneGraph\Nodes\CompositionNode;
use App\Services\AI\SceneGraph\Nodes\EyeAnchorNode;

/**
 * Resolves composition plan into a CompositionNode.
 *
 * Conflict resolution:
 *   full_frame + ruleOfThirds=true → override ruleOfThirds to false
 *   eyeAnchor from CompositionPlan → converted to typed EyeAnchorNode
 */
final class CompositionResolver
{
    public static function resolve(CompositionPlan $plan): CompositionNode
    {
        $subjectPosition = $plan->subjectPosition;
        $ruleOfThirds    = $plan->ruleOfThirds;

        if ($subjectPosition === 'full_frame') {
            $ruleOfThirds = false;
        }

        $eyeAnchor = $plan->eyeAnchor !== []
            ? EyeAnchorNode::from($plan->eyeAnchor)
            : null;

        return new CompositionNode(
            foreground:      $plan->foreground,
            midground:       $plan->midground,
            background:      $plan->background,
            negativeSpace:   $plan->negativeSpace,
            subjectPosition: $subjectPosition,
            ruleOfThirds:    $ruleOfThirds,
            leadingLines:    $plan->leadingLines,
            eyeAnchor:       $eyeAnchor,
        );
    }
}
