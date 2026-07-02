<?php

namespace App\Services\AI\ScenePlanner\Plans;

use App\Services\AI\SceneGraph\Nodes\DynamicStateNode;
use App\Services\AI\SceneGraph\Nodes\IdentityNode;

/**
 * Typed result from ContinuityPlanner::plan().
 *
 * character.identity and previousState are typed immediately here because
 * they're already well-structured. environment and camera remain as arrays
 * until ContinuityResolver converts them to EnvironmentNode + CameraContinuityNode.
 */
final class ContinuityPlan
{
    public function __construct(
        public readonly IdentityNode      $identity,
        public readonly DynamicStateNode  $dynamicState,
        /** Raw environment data — ContinuityResolver converts to EnvironmentNode */
        public readonly array             $environment,
        /** Raw camera data — ContinuityResolver converts to CameraContinuityNode */
        public readonly array             $camera,
        /** Raw constraint flags — ContinuityResolver converts to ContinuityConstraints */
        public readonly array             $constraints,
        /** Previous shot's dynamic state — null for shot 1 */
        public readonly ?DynamicStateNode $previousState,
    ) {}

    public static function fromArray(array $data): self
    {
        $character = $data['character'] ?? [];
        $prevRaw   = $data['previous_state'] ?? null;

        return new self(
            identity:      IdentityNode::from($character['identity']      ?? []),
            dynamicState:  DynamicStateNode::from($character['dynamic_state'] ?? []),
            environment:   $data['environment']  ?? [],
            camera:        $data['camera']        ?? [],
            constraints:   $data['constraints']   ?? [],
            previousState: $prevRaw !== null ? DynamicStateNode::from($prevRaw) : null,
        );
    }

    public function toArray(): array
    {
        return [
            'character'      => [
                'identity'      => $this->identity->toArray(),
                'dynamic_state' => $this->dynamicState->toArray(),
            ],
            'environment'    => $this->environment,
            'camera'         => $this->camera,
            'constraints'    => $this->constraints,
            'previous_state' => $this->previousState?->toArray(),
        ];
    }

    public function isFirstShot(): bool
    {
        return $this->previousState === null;
    }
}
