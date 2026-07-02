<?php

namespace App\Services\AI\SceneGraph;

use App\Services\AI\Contracts\ValidationResult;
use App\Services\AI\SceneGraph\Rules\CameraVocabRule;
use App\Services\AI\SceneGraph\Rules\CompositionConsistencyRule;
use App\Services\AI\SceneGraph\Rules\SceneRule;
use App\Services\AI\SceneGraph\Rules\SemanticPhaseRule;
use App\Services\AI\SceneGraph\Rules\SubjectPresenceRule;
use App\Services\AI\SceneGraph\Rules\TimelineContinuityRule;

/**
 * Open/Closed validator: iterates injected SceneRule implementations.
 *
 * Adding a new constraint = implement SceneRule + register it here.
 * This class never needs to change for new rules.
 *
 * Default rule set (Sprint 5):
 *   SubjectPresenceRule      — actor must be non-empty
 *   CameraVocabRule          — lensCode and height must be from valid vocabularies
 *   TimelineContinuityRule   — phases must be gapless and non-empty
 *   CompositionConsistencyRule — full_frame + rule_of_thirds=true is contradictory
 *   SemanticPhaseRule        — goal or primarySubject must be non-empty
 */
final class SceneGraphValidator
{
    /** @var SceneRule[] */
    private readonly array $rules;

    public function __construct(?array $rules = null)
    {
        $this->rules = $rules ?? self::defaultRules();
    }

    public function validate(ShotSceneGraph $graph): ValidationResult
    {
        $errors = [];

        foreach ($this->rules as $rule) {
            foreach ($rule->validate($graph) as $error) {
                $errors[] = $error;
            }
        }

        return $errors === []
            ? ValidationResult::pass()
            : ValidationResult::fail($errors);
    }

    /** @return SceneRule[] */
    private static function defaultRules(): array
    {
        return [
            new SubjectPresenceRule(),
            new CameraVocabRule(),
            new TimelineContinuityRule(),
            new CompositionConsistencyRule(),
            new SemanticPhaseRule(),
        ];
    }
}
