<?php

namespace App\Services\AI\ScenePlanner\Plans;

/**
 * Typed result from ActionPlanner::plan().
 *
 * Contains the event-driven action decomposition for a single shot:
 * what the actor does, what they hold, the phase timeline, camera beats,
 * and physics trigger codes that PhysicsPlanner will expand.
 */
final class ActionPlan
{
    public function __construct(
        public readonly string $actionType,
        public readonly string $primaryActor,
        public readonly string $objectInHand,
        /** Raw phase arrays: [{start, end, subject}] — converted to PhaseNode[] by TimelineResolver */
        public readonly array  $phases,
        /** Camera beat timings: [{time, weight, move, context?}] */
        public readonly array  $cameraBeats,
        /** Physics trigger codes: ['dust_spray', 'impact_dust', …] */
        public readonly array  $physicsTriggers,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            actionType:      $data['action_type']      ?? 'generic_action',
            primaryActor:    $data['primary_actor']    ?? '',
            objectInHand:    $data['object_in_hand']   ?? '',
            phases:          $data['timeline']         ?? [],
            cameraBeats:     $data['camera_beats']     ?? [],
            physicsTriggers: $data['physics_triggers'] ?? [],
        );
    }

    /** Backward-compat serialization — matches ContinuityPlanner's expectation. */
    public function toArray(): array
    {
        return [
            'action_type'      => $this->actionType,
            'primary_actor'    => $this->primaryActor,
            'object_in_hand'   => $this->objectInHand,
            'timeline'         => $this->phases,
            'camera_beats'     => $this->cameraBeats,
            'physics_triggers' => $this->physicsTriggers,
        ];
    }
}
