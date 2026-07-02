<?php

namespace App\Services\AI\SceneGraph\Rules;

use App\Services\AI\SceneGraph\ShotSceneGraph;

/**
 * Validates that the semantic node is coherently populated.
 *
 * storyPhase is a StoryPhase enum — always valid by construction.
 * This rule checks the string fields that remain untyped (goal, viewerAttention).
 */
final class SemanticPhaseRule implements SceneRule
{
    public function validate(ShotSceneGraph $graph): array
    {
        $errors = [];

        if ($graph->semantic->goal === '' && $graph->semantic->primarySubject === '') {
            $errors[] = [
                'field'    => 'semantic.goal + semantic.primary_subject',
                'expected' => 'at least one of goal or primary_subject to be non-empty',
                'actual'   => 'both empty',
            ];
        }

        return $errors;
    }

    public function name(): string
    {
        return 'semantic_phase';
    }
}
