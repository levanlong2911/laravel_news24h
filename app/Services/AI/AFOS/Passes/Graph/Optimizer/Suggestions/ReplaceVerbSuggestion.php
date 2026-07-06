<?php

namespace App\Services\AI\AFOS\Passes\Graph\Optimizer\Suggestions;

use App\Services\AI\AFOS\Ir\Temporal\NodeRef;
use App\Services\AI\AFOS\Passes\Graph\Optimizer\OptimizationSuggestion;

/**
 * ReplaceVerbSuggestion — substitute a motion verb with a more semantically appropriate one.
 *
 * Emitted when:
 *   - MotionVerbRegistry::similarity($currentVerb, $proposedVerb) > threshold
 *   - The proposed verb is more canonical for the current backend
 *     (e.g. Kling renders 'stride' better than 'march' for high-energy FOLLOW shots)
 *   - The beat's $confidence < 0.7 (planner was uncertain about verb choice)
 *
 * SuggestionExecutor hands this to ReplaceVerbHandler, which creates a new
 * MotionBeat with $proposedVerb replacing $currentVerb on the affected event.
 * All relations are preserved — only the verb changes.
 *
 * $proposedVerb should be the canonical form from MotionVerbRegistry::canonicalForm().
 */
final class ReplaceVerbSuggestion implements OptimizationSuggestion
{
    public const TYPE = 'replace_verb';

    public function __construct(
        public readonly NodeRef $node,
        public readonly string  $currentVerb,
        public readonly string  $proposedVerb,
        public readonly float   $similarity,
        public readonly string  $rationale,
        public readonly float   $confidence = 0.85,
    ) {}

    public function suggestionType(): string { return self::TYPE; }

    /** @return NodeRef[] */
    public function affectedNodes(): array { return [$this->node]; }

    public function rationale(): string { return $this->rationale; }

    public function confidence(): float { return $this->confidence; }
}
