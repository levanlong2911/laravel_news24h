<?php

namespace App\Services\AI\SceneGraph\Nodes;

/**
 * Subject dynamic state at END of a shot.
 *
 * This becomes the previousState context for shot N+1:
 * "Continuing from: [actionPhase]" in the CONTINUITY section.
 * Physical plausibility across cuts depends on this chain.
 */
final class DynamicStateNode
{
    public function __construct(
        /** What the subject was doing at the end of this shot */
        public readonly string $actionPhase,
        /** Action grammar type (for cross-shot consistency) */
        public readonly string $actionType,
        /** Object held at end of shot */
        public readonly string $objectInHand,
    ) {}

    public static function from(array $data): self
    {
        return new self(
            actionPhase:  $data['action_phase']   ?? '',
            actionType:   $data['action_type']    ?? '',
            objectInHand: $data['object_in_hand'] ?? '',
        );
    }

    public function toArray(): array
    {
        return [
            'action_phase'   => $this->actionPhase,
            'action_type'    => $this->actionType,
            'object_in_hand' => $this->objectInHand,
        ];
    }

    public function isEmpty(): bool
    {
        return $this->actionPhase === '' && $this->objectInHand === '';
    }
}
