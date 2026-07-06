<?php

namespace App\Services\AI\AFOS\Ir\Temporal;

/**
 * EventRelation — a typed, directed edge from one TimelineEvent to another.
 *
 * Replaces the plain string[] dependsOn on TimelineEvent with a richer model
 * that distinguishes temporal constraints (Hard, Follows, Interrupts, Overlaps)
 * from semantic relationships (Supports, Mirrors, BlendsInto).
 *
 * Temporal constraints are read by validators and the scheduler.
 * Semantic constraints are read by the serializer and the optimizer hint system.
 *
 * $metadata carries relation-specific semantic context that the Optimizer can
 * use for reasoning without parsing the event content:
 *
 *   Hard      → ['reason' => 'kinematics']
 *   Supports  → ['reason' => 'balance', 'axis' => 'vertical']
 *   Mirrors   → ['axis' => 'left_right']
 *   Follows   → ['reason' => 'momentum_transfer']
 */
final class EventRelation
{
    public function __construct(
        /** ID of the event this relation points to. */
        public readonly string       $targetId,
        public readonly RelationType $type,
        /** 0.0–1.0 influence strength, used by semantic constraints. */
        public readonly float        $weight   = 1.0,
        /** Relation-specific semantic context for the Optimizer and benchmark viewer. */
        public readonly array        $metadata = [],
    ) {}
}
