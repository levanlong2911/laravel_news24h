<?php

namespace App\Services\AI\AFOS\Passes\Graph\Optimizer\Suggestions;

use App\Services\AI\AFOS\Ir\Temporal\NodeRef;
use App\Services\AI\AFOS\Passes\Graph\Optimizer\OptimizationSuggestion;

/**
 * DeleteBeatSuggestion — remove a low-confidence or redundant beat from a track.
 *
 * Emitted when:
 *   - $event->confidence < threshold (beat was generated speculatively)
 *   - Beat has no Hard dependents and its Follows dependents can be re-anchored
 *   - Beat's verb is substitutable via MotionVerbRegistry with similarity > 0.9
 *     and the replacement verb is already covered by an adjacent beat
 *
 * SuggestionExecutor hands this to DeleteBeatHandler, which:
 *   1. Removes the event from its track
 *   2. Re-anchors any Follows edges that pointed to/from the deleted event
 *   3. Returns the modified TemporalGraph
 *
 * Does NOT remove Hard-related events — only orphaned or low-confidence beats.
 */
final class DeleteBeatSuggestion implements OptimizationSuggestion
{
    public const TYPE = 'delete_beat';

    public function __construct(
        public readonly NodeRef $node,
        public readonly float   $beatConfidence,
        public readonly string  $rationale,
        public readonly float   $confidence = 0.8,
    ) {}

    public function suggestionType(): string { return self::TYPE; }

    /** @return NodeRef[] */
    public function affectedNodes(): array { return [$this->node]; }

    public function rationale(): string { return $this->rationale; }

    public function confidence(): float { return $this->confidence; }
}
