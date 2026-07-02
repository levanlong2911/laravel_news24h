<?php

namespace App\Services\AI\SceneGraph\Nodes;

use App\Services\AI\SceneGraph\Enums\Pacing;

/**
 * Resolved editorial and pacing decisions for a single shot.
 *
 * pacing is a backed enum — always valid.
 * motionBlur is normalized to float [0.0, 1.0] by DirectorResolver.
 */
final class DirectorNode
{
    public function __construct(
        public readonly Pacing $pacing,
        public readonly string $framing,
        public readonly string $shotPriority,
        /** Normalized motion blur: 0.0 (none) – 1.0 (heavy) */
        public readonly float  $motionBlur,
        public readonly bool   $rackFocus,
        /** Acceleration profile stub — Sprint 8 Motion Engine */
        public readonly string $acceleration,
    ) {}

    public static function from(array $data): self
    {
        return new self(
            pacing:       Pacing::fromString($data['pacing']        ?? 'medium'),
            framing:      $data['framing']       ?? 'standard',
            shotPriority: $data['shot_priority'] ?? 'subject',
            motionBlur:   min(1.0, max(0.0, (float) ($data['motion_blur'] ?? 0.3))),
            rackFocus:    (bool) ($data['rack_focus']   ?? false),
            acceleration: $data['acceleration']  ?? 'linear',
        );
    }

    public function toArray(): array
    {
        return [
            'pacing'        => $this->pacing->value,
            'framing'       => $this->framing,
            'shot_priority' => $this->shotPriority,
            'motion_blur'   => $this->motionBlur,
            'rack_focus'    => $this->rackFocus,
            'acceleration'  => $this->acceleration,
        ];
    }
}
