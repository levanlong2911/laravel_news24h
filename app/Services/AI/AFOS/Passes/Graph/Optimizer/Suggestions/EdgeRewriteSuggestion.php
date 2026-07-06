<?php

namespace App\Services\AI\AFOS\Passes\Graph\Optimizer\Suggestions;

use App\Services\AI\AFOS\Ir\Temporal\EventEdge;
use App\Services\AI\AFOS\Ir\Temporal\NodeRef;
use App\Services\AI\AFOS\Ir\Temporal\RelationType;
use App\Services\AI\AFOS\Passes\Graph\Optimizer\OptimizationSuggestion;

/**
 * EdgeRewriteSuggestion — replace an edge's RelationType with a different one.
 *
 * Emitted when the Optimizer determines that the semantic intent of an edge would
 * be better expressed by a different relation type at the energy level observed.
 *
 * Example: Follows → BlendsInto when $energy > 0.8 and both events overlap by < 0.2s.
 * The temporal constraint (sequence) is relaxed to a semantic hint (continuity),
 * allowing the scheduler more freedom.
 *
 * Note: isStructural() edges (Hard, Supports, Mirrors) should not be rewritten
 * by automated passes without explicit human override.
 */
final class EdgeRewriteSuggestion implements OptimizationSuggestion
{
    public const TYPE = 'edge_rewrite';

    public function __construct(
        public readonly EventEdge    $edge,
        public readonly RelationType $newType,
        public readonly string       $rationale,
        public readonly float        $confidence = 1.0,
    ) {}

    public function suggestionType(): string { return self::TYPE; }

    /** @return NodeRef[] */
    public function affectedNodes(): array
    {
        return [$this->edge->from, $this->edge->to];
    }

    public function rationale(): string { return $this->rationale; }

    public function confidence(): float { return $this->confidence; }
}
