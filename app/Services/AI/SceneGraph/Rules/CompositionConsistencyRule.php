<?php

namespace App\Services\AI\SceneGraph\Rules;

use App\Services\AI\SceneGraph\ShotSceneGraph;

/**
 * Validates that composition settings are internally consistent.
 *
 * full_frame + ruleOfThirds=true is physically contradictory:
 * the subject can't fill the frame AND be placed by rule-of-thirds.
 * CompositionResolver normalizes this — if it survives to here, it's a bug.
 */
final class CompositionConsistencyRule implements SceneRule
{
    public function validate(ShotSceneGraph $graph): array
    {
        $errors = [];

        if ($graph->composition->subjectPosition === 'full_frame'
            && $graph->composition->ruleOfThirds === true
        ) {
            $errors[] = [
                'field'    => 'composition.rule_of_thirds',
                'expected' => 'false when subject_position=full_frame',
                'actual'   => 'true — CompositionResolver normalization did not apply',
            ];
        }

        if ($graph->composition->eyeAnchor !== null
            && $graph->composition->eyeAnchor->strength < 0.0
        ) {
            $errors[] = [
                'field'    => 'composition.eye_anchor.strength',
                'expected' => '0.0–1.0',
                'actual'   => $graph->composition->eyeAnchor->strength,
            ];
        }

        return $errors;
    }

    public function name(): string
    {
        return 'composition_consistency';
    }
}
