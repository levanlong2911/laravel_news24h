<?php

namespace App\Services\AI\AFOS\Ir\Temporal;

/**
 * CameraKeyframe — one anchor point in the camera arc.
 *
 * Pure event node: carries semantic fields (frameSize, cameraAction, speed, lens, focusTarget)
 * and timing (atSec as a point event) only. Graph structure lives in EdgeStore.
 *
 * A point event (startSec = endSec = atSec). The transition between two
 * consecutive keyframes is implied by the serializer — it spans from this
 * keyframe's atSec to the next one's atSec.
 *
 * frameSizes:    'extreme_wide' | 'wide' | 'medium' | 'close' | 'extreme_close'
 * cameraActions: 'static' | 'push' | 'pull' | 'orbit' | 'track' |
 *                'crane_up' | 'crane_down' | 'hold' | 'pan' | 'tilt' | 'roll'
 */
final class CameraKeyframe extends TimelineEvent
{
    public function __construct(
        string          $id,
        float           $atSec,
        public readonly string       $frameSize,
        public readonly string       $cameraAction,
        public readonly float        $speed,          // 0.0 (locked) – 1.0 (maximum)
        public readonly ?int         $lensMs      = null,   // focal length override
        public readonly ?string      $focusTarget = null,   // 'stadium' | 'face' | 'hands' | 'pool'
        float           $confidence              = 1.0,
        EventOrigin     $origin                  = EventOrigin::CameraArcStage,
        int             $priority                = 0,
        string          $layer                   = 'camera',
        ?string         $label                   = null,
    ) {
        parent::__construct($id, $atSec, $atSec, $confidence, $origin, $priority, $layer, $label);
    }
}
