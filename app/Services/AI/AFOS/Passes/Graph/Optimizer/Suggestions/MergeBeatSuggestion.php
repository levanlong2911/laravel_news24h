<?php

namespace App\Services\AI\AFOS\Passes\Graph\Optimizer\Suggestions;

use App\Services\AI\AFOS\Ir\Temporal\NodeRef;
use App\Services\AI\AFOS\Passes\Graph\Optimizer\OptimizationSuggestion;

/**
 * MergeBeatSuggestion — fuse two adjacent beats on the same actor/channel.
 *
 * Emitted when:
 *   - $nodeA and $nodeB are adjacent (nodeB.startSec ≈ nodeA.endSec, delta < 0.1s)
 *   - Both beats share the same actor and channel
 *   - MotionVerbRegistry::similarity($verbA, $verbB) > 0.85
 *   - No structural edges exist between them (Hard, Supports, Mirrors)
 *
 * SuggestionExecutor hands this to MergeBeatHandler, which:
 *   1. Creates a new beat spanning [nodeA.startSec, nodeB.endSec]
 *   2. Merges relations from both beats (deduplicating)
 *   3. Returns the modified TemporalGraph
 *
 * $mergedVerb is the canonical verb to use for the resulting beat.
 */
final class MergeBeatSuggestion implements OptimizationSuggestion
{
    public const TYPE = 'merge_beat';

    public function __construct(
        public readonly NodeRef $nodeA,
        public readonly NodeRef $nodeB,
        public readonly string  $mergedVerb,
        public readonly string  $rationale,
        public readonly float   $confidence = 0.9,
    ) {}

    public function suggestionType(): string { return self::TYPE; }

    /** @return NodeRef[] */
    public function affectedNodes(): array { return [$this->nodeA, $this->nodeB]; }

    public function rationale(): string { return $this->rationale; }

    public function confidence(): float { return $this->confidence; }
}
