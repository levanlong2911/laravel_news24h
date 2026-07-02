<?php

namespace App\Services\AI\ScenePlanner;

/**
 * Rule-based: shot DSL → director{} full cinematic metadata.
 *
 * Produces structured camera and pacing intent that any renderer can read
 * directly — no inference required. Expanding this metadata is the right
 * way to improve cinematic quality across all providers (Kling, Veo, Seedance).
 */
final class DirectorPlanner
{
    /** Emotion code → base director preset. */
    private const EMO_PRESETS = [
        'POWER' => [
            'pacing'         => 'dynamic',
            'framing'        => 'center',
            'camera_height'  => 'eye-level',
            'shot_priority'  => 'subject',
            'acceleration'   => 'aggressive',
            'stabilization'  => 'handheld',
            'motion_blur'    => 'natural',
            'composition'    => 'rule_of_thirds',
            'rack_focus'     => false,
        ],
        'JOY' => [
            'pacing'         => 'upbeat',
            'framing'        => 'medium',
            'camera_height'  => 'eye-level',
            'shot_priority'  => 'subject',
            'acceleration'   => 'smooth',
            'stabilization'  => 'steady',
            'motion_blur'    => 'natural',
            'composition'    => 'centered',
            'rack_focus'     => false,
        ],
        'EPIC' => [
            'pacing'         => 'slow',
            'framing'        => 'wide',
            'camera_height'  => 'low-angle',
            'shot_priority'  => 'scene',
            'acceleration'   => 'smooth',
            'stabilization'  => 'gimbal',
            'motion_blur'    => 'natural',
            'composition'    => 'rule_of_thirds',
            'rack_focus'     => false,
        ],
        'TENSE' => [
            'pacing'         => 'medium',
            'framing'        => 'tight',
            'camera_height'  => 'eye-level',
            'shot_priority'  => 'subject',
            'acceleration'   => 'handheld',
            'stabilization'  => 'handheld',
            'motion_blur'    => 'natural',
            'composition'    => 'centered',
            'rack_focus'     => true,
        ],
        'AWE' => [
            'pacing'         => 'slow',
            'framing'        => 'wide',
            'camera_height'  => 'aerial',
            'shot_priority'  => 'scene',
            'acceleration'   => 'smooth',
            'stabilization'  => 'gimbal',
            'motion_blur'    => 'minimal',
            'composition'    => 'rule_of_thirds',
            'rack_focus'     => false,
        ],
        'DRAMA' => [
            'pacing'         => 'medium',
            'framing'        => 'center',
            'camera_height'  => 'eye-level',
            'shot_priority'  => 'subject',
            'acceleration'   => 'smooth',
            'stabilization'  => 'steady',
            'motion_blur'    => 'natural',
            'composition'    => 'centered',
            'rack_focus'     => true,
        ],
        'REVEAL' => [
            'pacing'         => 'slow',
            'framing'        => 'wide',
            'camera_height'  => 'high-angle',
            'shot_priority'  => 'scene',
            'acceleration'   => 'smooth',
            'stabilization'  => 'gimbal',
            'motion_blur'    => 'minimal',
            'composition'    => 'rule_of_thirds',
            'rack_focus'     => false,
        ],
        'CALM' => [
            'pacing'         => 'slow',
            'framing'        => 'centered',
            'camera_height'  => 'eye-level',
            'shot_priority'  => 'subject',
            'acceleration'   => 'smooth',
            'stabilization'  => 'gimbal',
            'motion_blur'    => 'minimal',
            'composition'    => 'centered',
            'rack_focus'     => false,
        ],
        'HOOK' => [
            'pacing'         => 'fast',
            'framing'        => 'tight',
            'camera_height'  => 'low-angle',
            'shot_priority'  => 'subject',
            'acceleration'   => 'snap',
            'stabilization'  => 'handheld',
            'motion_blur'    => 'high',
            'composition'    => 'centered',
            'rack_focus'     => false,
        ],
        'CRAFT' => [
            'pacing'         => 'medium',
            'framing'        => 'center',
            'camera_height'  => 'eye-level',
            'shot_priority'  => 'subject',
            'acceleration'   => 'smooth',
            'stabilization'  => 'steady',
            'motion_blur'    => 'natural',
            'composition'    => 'rule_of_thirds',
            'rack_focus'     => false,
        ],
        'FEAR' => [
            'pacing'         => 'slow',
            'framing'        => 'tight',
            'camera_height'  => 'low-angle',
            'shot_priority'  => 'subject',
            'acceleration'   => 'handheld',
            'stabilization'  => 'handheld',
            'motion_blur'    => 'natural',
            'composition'    => 'rule_of_thirds',
            'rack_focus'     => true,
        ],
    ];

    /** Camera code → lens recommendation in mm. */
    private const CAM_LENS = [
        'AERIAL'   => '35mm',
        'TRACKING' => '85mm',
        'ORBITAL'  => '50mm',
        'WIDE'     => '24mm',
        'MEDIUM'   => '50mm',
        'CLOSE'    => '85mm',
        'MACRO'    => '135mm',
        'POV'      => '35mm',
    ];

    /** Camera code → height override. */
    private const CAM_HEIGHT = [
        'AERIAL'  => 'aerial',
        'MACRO'   => 'ground-level',
        'POV'     => 'eye-level',
    ];

    /** Motion level overrides for pacing and acceleration. */
    private const MOTION_OVERRIDE = [
        'high' => ['pacing' => 'dynamic', 'acceleration' => 'aggressive', 'stabilization' => 'handheld'],
        'low'  => ['pacing' => 'slow',    'acceleration' => 'smooth',     'stabilization' => 'gimbal'],
    ];

    public function plan(array $dsl): array
    {
        $emoCode     = $dsl['emo']          ?? 'CRAFT';
        $camCode     = $dsl['cam']          ?? 'MEDIUM';
        $motionLevel = $dsl['motion_level'] ?? 'medium';

        $director = self::EMO_PRESETS[$emoCode] ?? self::EMO_PRESETS['CRAFT'];

        // Camera code can override lens and height
        $director['lens'] = self::CAM_LENS[$camCode] ?? '50mm';
        if (isset(self::CAM_HEIGHT[$camCode])) {
            $director['camera_height'] = self::CAM_HEIGHT[$camCode];
        }

        // Motion level overrides pacing, acceleration, stabilization (heuristic defaults)
        $override = self::MOTION_OVERRIDE[$motionLevel] ?? [];
        foreach ($override as $key => $value) {
            $director[$key] = $value;
        }

        // DSL hard-lock: explicit DSL values beat planner heuristics.
        // A creator specifying `stabilization: gimbal` knows their equipment intent;
        // the motion-level inference is a fallback, not an authority.
        if (!empty($dsl['stabilization'])) {
            $director['stabilization'] = $dsl['stabilization'];
        }
        if (!empty($dsl['camera_height'])) {
            $director['camera_height'] = $dsl['camera_height'];
        }

        return $director;
    }
}
