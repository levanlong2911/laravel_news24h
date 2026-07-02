<?php

namespace App\Services\AI\PromptCompiler\Libraries;

/**
 * Rule-based motion level assignment.
 * Keeps motion_level out of Claude's hands — Rule 2 in spirit.
 *
 * Priority: ShotPurpose override → information_type default → fallback 'low'.
 */
final class MotionRulesLibrary
{
    public const VERSION = '1.0';

    // information_type → default motion_level for all shots in that beat
    private const TYPE_MOTION = [
        'PROCESS'     => 'medium',
        'FACT'        => 'low',
        'COMPARISON'  => 'low',
        'EMOTION'     => 'medium',
        'DETAIL'      => 'low',
        'SPECULATION' => 'high',
        'SUMMARY'     => 'low',
    ];

    // ShotPurpose → motion override (wins over information_type default)
    private const PURPOSE_OVERRIDE = [
        'HOOK'       => 'high',
        'TRANSITION' => 'high',
        'MACRO'      => 'low',   // Macro shots always slow for sharpness
        'PAYOFF'     => 'low',   // Payoff shots linger
        'EMOTION'    => 'none',  // Emotion shots can be static
    ];

    public static function motionLevel(string $informationType, string $purpose): string
    {
        if (isset(self::PURPOSE_OVERRIDE[$purpose])) {
            return self::PURPOSE_OVERRIDE[$purpose];
        }
        return self::TYPE_MOTION[strtoupper($informationType)] ?? 'low';
    }
}
