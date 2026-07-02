<?php

namespace App\Services\AI\SceneGraph\Nodes;

use App\Services\AI\SceneGraph\Enums\CameraMove;
use App\Services\AI\SceneGraph\Enums\CameraType;
use App\Services\AI\SceneGraph\Enums\MotionLevel;

/**
 * Resolved camera setup for a single shot.
 *
 * move and camType are backed enums — they are always valid by construction.
 * Validators only need to check the string fields (lensCode, height).
 */
final class CameraNode
{
    public function __construct(
        public readonly CameraMove  $move,
        public readonly CameraType  $camType,
        /** Raw numeric lens code for LENS_EFFECT lookups: 16, 24, 35, 50, 85, 135, 200 */
        public readonly string      $lensCode,
        /** Camera height: eye-level, low, high, aerial, low-angle, high-angle, ground-level */
        public readonly string      $height,
        public readonly MotionLevel $motionLevel,
        /** Stabilization style: steady, handheld, fluid-head, gimbal */
        public readonly string      $stabilization,
        /** Subject frame position cross-linked from CompositionNode */
        public readonly string      $subjectPosition,
    ) {}

    public static function from(array $data): self
    {
        return new self(
            move:            CameraMove::fromDsl($data['move']     ?? 'STATIC'),
            camType:         CameraType::fromDsl($data['cam_code'] ?? 'MEDIUM'),
            lensCode:        $data['lens_code']        ?? '50',
            height:          $data['height']           ?? 'eye-level',
            motionLevel:     MotionLevel::fromString($data['motion_level'] ?? 'medium'),
            stabilization:   $data['stabilization']    ?? 'steady',
            subjectPosition: $data['subject_position'] ?? 'center',
        );
    }

    public function toArray(): array
    {
        return [
            'move'             => $this->move->value,
            'cam_code'         => $this->camType->value,
            'lens_code'        => $this->lensCode,
            'height'           => $this->height,
            'motion_level'     => $this->motionLevel->value,
            'stabilization'    => $this->stabilization,
            'subject_position' => $this->subjectPosition,
        ];
    }
}
