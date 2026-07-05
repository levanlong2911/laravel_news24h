<?php

namespace App\Services\AI\AFOS\Compiler\Diagnostics;

/**
 * DiagnosticCode — machine-readable compiler error codes.
 *
 * Numbering scheme:
 *   AFOS10xx — ShotGoalIR validation
 *   AFOS11xx — CameraIR validation
 *
 * Usage in CI / telemetry: filter by code prefix rather than string matching.
 * IDE tooling: map code → documentation URL, quick-fix suggestion.
 */
enum DiagnosticCode: string
{
    // ShotGoalIR (AFOS10xx)
    case DURATION_ZERO          = 'AFOS1001';
    case DURATION_BELOW_MINIMUM = 'AFOS1002';
    case DURATION_EXCEEDS_LIMIT = 'AFOS1003';
    case ENERGY_OUT_OF_RANGE    = 'AFOS1004';
    case GOAL_TARGET_EMPTY      = 'AFOS1005';

    // CameraIR (AFOS11xx)
    case FOCAL_BELOW_MINIMUM    = 'AFOS1101';
    case FOCAL_EXCEEDS_MAXIMUM  = 'AFOS1102';
    case APERTURE_INVALID       = 'AFOS1103';
    case STATIC_WITH_VELOCITY   = 'AFOS1104';
    case TELEPHOTO_HANDHELD     = 'AFOS1105';
}
