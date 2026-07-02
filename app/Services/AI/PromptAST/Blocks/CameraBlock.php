<?php

namespace App\Services\AI\PromptAST\Blocks;

use App\Services\AI\SceneGraph\Enums\CameraMove;
use App\Services\AI\SceneGraph\Enums\CameraType;
use App\Services\AI\SceneGraph\Enums\MotionLevel;
use App\Services\AI\SceneGraph\Nodes\EyeAnchorNode;

/**
 * Semantic camera setup block.
 *
 * All movement semantics are preserved as typed enums.
 * KlingSerializer maps CameraMove::P2 × MotionLevel::HIGH → "aggressively pushing in...", etc.
 * VeoSerializer maps the same enum to its own phrasing.
 */
final class CameraBlock
{
    public function __construct(
        public readonly CameraMove      $move,
        public readonly CameraType      $camType,
        /** Raw numeric lens code: '16', '24', '35', '50', '85', '135', '200' */
        public readonly string          $lensCode,
        public readonly MotionLevel     $motionLevel,
        /** Camera height: eye-level, low, high, aerial, low-angle, high-angle, ground-level */
        public readonly string          $height,
        /** Stabilization style: steady, handheld, fluid-head, gimbal */
        public readonly string          $stabilization,
        /** Frame position: center, left_third, right_third, lower_third, full_frame, leading_third */
        public readonly string          $subjectPosition,
        public readonly string          $leadingLines,
        public readonly bool            $ruleOfThirds,
        public readonly ?EyeAnchorNode  $eyeAnchor,
    ) {}
}
