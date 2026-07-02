<?php

namespace App\Services\AI\SceneGraph\Nodes;

/**
 * Cross-shot visual continuity state for a single shot.
 *
 * Produced by ContinuityResolver from ContinuityPlan output.
 * Enables the renderer to inject a CONTINUITY section into shots 2+ of a scene
 * so the AI model has no reason to invent a new face, jersey, or weather.
 *
 * Two character layers:
 *   identity      — locked from shot 1; never changes within a scene
 *   dynamicState  — chained from the previous shot's end state
 *
 * constraints flags tell each renderer which CONTINUITY directives are mandatory.
 * previousState is null for shot 1; non-null for all subsequent shots.
 *
 * Sprint 5: all array fields replaced with typed sub-nodes.
 */
final class ContinuityNode
{
    public function __construct(
        /** Identity fields locked from shot 1 (role, jersey, helmet, etc.) */
        public readonly IdentityNode          $identity,
        /** Dynamic state at END of this shot — becomes previousState for shot N+1 */
        public readonly DynamicStateNode      $dynamicState,
        /** Scene environment locked across shots (weather, palette, time, etc.) */
        public readonly EnvironmentNode       $environment,
        /** Camera setup hints for subsequent shots */
        public readonly CameraContinuityNode  $camera,
        /** Which aspects the renderer MUST preserve */
        public readonly ContinuityConstraints $constraints,
        /** Dynamic state from the previous shot — null for shot 1 */
        public readonly ?DynamicStateNode     $previousState,
    ) {}

    public static function from(array $data): self
    {
        $character = $data['character'] ?? [];

        return new self(
            identity:      IdentityNode::from($character['identity']           ?? []),
            dynamicState:  DynamicStateNode::from($character['dynamic_state']  ?? []),
            environment:   EnvironmentNode::from($data['environment']          ?? []),
            camera:        CameraContinuityNode::from($data['camera']          ?? []),
            constraints:   ContinuityConstraints::from($data['constraints']    ?? []),
            previousState: isset($data['previous_state'])
                ? DynamicStateNode::from($data['previous_state'])
                : null,
        );
    }

    public function toArray(): array
    {
        return [
            'character'      => [
                'identity'      => $this->identity->toArray(),
                'dynamic_state' => $this->dynamicState->toArray(),
            ],
            'environment'    => $this->environment->toArray(),
            'camera'         => $this->camera->toArray(),
            'constraints'    => $this->constraints->toArray(),
            'previous_state' => $this->previousState?->toArray(),
        ];
    }

    /** True if this is the first shot in the scene (no previous state to inject). */
    public function isFirstShot(): bool
    {
        return $this->previousState === null;
    }
}
