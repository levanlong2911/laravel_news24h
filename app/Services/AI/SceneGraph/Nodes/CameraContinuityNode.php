<?php

namespace App\Services\AI\SceneGraph\Nodes;

/**
 * Camera setup that must be preserved across shots in a scene.
 *
 * Injected into the CONTINUITY section so subsequent shots don't
 * feel like they were shot on a different camera rig.
 */
final class CameraContinuityNode
{
    public function __construct(
        /** Focal length code: 24, 35, 50, 85, 135, 200 */
        public readonly string $lens,
        /** Camera height: eye-level, low, high, aerial, ground-level */
        public readonly string $height,
        /** Default angle label */
        public readonly string $angle,
        /** Stabilization style: steady, handheld, gimbal */
        public readonly string $cameraStyle,
        /** Move code: STATIC, P1, D1, etc. */
        public readonly string $movement,
    ) {}

    public static function from(array $data): self
    {
        return new self(
            lens:        $data['lens']         ?? '50mm',
            height:      $data['height']       ?? 'eye-level',
            angle:       $data['angle']        ?? 'front_left',
            cameraStyle: $data['camera_style'] ?? 'steady',
            movement:    $data['movement']     ?? 'STATIC',
        );
    }

    public function toArray(): array
    {
        return [
            'lens'         => $this->lens,
            'height'       => $this->height,
            'angle'        => $this->angle,
            'camera_style' => $this->cameraStyle,
            'movement'     => $this->movement,
        ];
    }
}
