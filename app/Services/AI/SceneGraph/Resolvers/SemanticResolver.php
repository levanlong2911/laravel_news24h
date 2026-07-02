<?php

namespace App\Services\AI\SceneGraph\Resolvers;

use App\Services\AI\ScenePlanner\Plans\SemanticPlan;
use App\Services\AI\SceneGraph\Nodes\SemanticNode;

/**
 * Resolves semantic plan into a typed SemanticNode.
 *
 * Direct conversion — SemanticPlan already holds typed enums (Emotion,
 * Pacing, StoryPhase), so the resolver is a thin mapping layer.
 */
final class SemanticResolver
{
    public static function resolve(SemanticPlan $plan): SemanticNode
    {
        return new SemanticNode(
            goal:             $plan->goal,
            emotion:          $plan->emotion,
            pace:             $plan->pace,
            primarySubject:   $plan->primarySubject,
            secondarySubject: $plan->secondarySubject,
            viewerAttention:  $plan->viewerAttention,
            storyPhase:       $plan->storyPhase,
            viewerTakeaway:   $plan->viewerTakeaway,
        );
    }
}
