<?php

namespace App\Services\AI\AFOS\Ir\Temporal;

/**
 * EventEdge — a typed, directed edge stored in the EdgeStore.
 *
 * Replaces EventRelation (which is embedded in TimelineEvent.relations[]) as the
 * canonical representation of a graph edge in Round 9. EventEdge is stored in
 * EdgeStore, NOT on the event node — edges are graph structure, not node state.
 *
 * Differences from EventRelation:
 *   - Has an explicit $from NodeRef (EventRelation only had $targetId)
 *   - Both endpoints are NodeRef (trackId + eventId), enabling cross-track edges
 *   - Stored centrally in EdgeStore — single source of truth for graph structure
 *
 * Temporal constraints (Hard, Follows, Interrupts, Overlaps) are read by the
 * Scheduler and TrackValidator. Semantic constraints (Supports, Mirrors, BlendsInto)
 * are read by the Serializer and Optimizer hint system.
 *
 * metadata examples:
 *   Hard      → ['reason' => 'kinematics']
 *   Supports  → ['reason' => 'balance', 'axis' => 'vertical']
 *   Mirrors   → ['axis' => 'left_right']
 *   Follows   → ['confidence' => 0.81, 'generatedBy' => 'MotionGrammar']
 */
final class EventEdge
{
    public function __construct(
        public readonly NodeRef      $from,
        public readonly NodeRef      $to,
        public readonly RelationType $type,
        /** 0.0–1.0 influence strength, used by semantic constraints. */
        public readonly float        $weight   = 1.0,
        /** Relation-specific semantic context for the Optimizer and benchmark viewer. */
        public readonly array        $metadata = [],
    ) {}

    public function isCrossTrack(): bool
    {
        return $this->from->trackId !== $this->to->trackId;
    }

    public function isSameTrack(): bool
    {
        return $this->from->trackId === $this->to->trackId;
    }

    /**
     * Returns a new edge with the same endpoints but a different RelationType.
     * Used by EdgeRewriteSuggestion when applied by SuggestionExecutor.
     */
    public function withType(RelationType $type): self
    {
        return new self($this->from, $this->to, $type, $this->weight, $this->metadata);
    }

    public function withMetadata(array $metadata): self
    {
        return new self($this->from, $this->to, $this->type, $this->weight, $metadata);
    }

    public function withWeight(float $weight): self
    {
        return new self($this->from, $this->to, $this->type, $weight, $this->metadata);
    }
}
