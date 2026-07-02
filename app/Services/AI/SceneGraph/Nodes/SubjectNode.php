<?php

namespace App\Services\AI\SceneGraph\Nodes;

/**
 * Resolved subject (primary actor) for a single shot.
 *
 * Produced by SceneGraphBuilder from ActionPlanner output.
 * Captures who is in the frame, what they are doing, and what they are holding.
 */
final class SubjectNode
{
    public function __construct(
        /** Human-readable role label: "quarterback", "goalkeeper", "driver" */
        public readonly string $actor,
        /** Action grammar type: fb_throw, fb_catch, bb_dunk, sc_goal, drive_drift, … */
        public readonly string $actionType,
        /** Object being held or interacted with: "football", "basketball", "steering wheel" */
        public readonly string $objectInHand,
    ) {}

    public static function from(array $data): self
    {
        return new self(
            actor:        $data['actor']          ?? '',
            actionType:   $data['action_type']    ?? 'generic_action',
            objectInHand: $data['object_in_hand'] ?? '',
        );
    }

    public function toArray(): array
    {
        return [
            'actor'          => $this->actor,
            'action_type'    => $this->actionType,
            'object_in_hand' => $this->objectInHand,
        ];
    }
}
