<?php

namespace App\Services\AI\AFOS\Compiler\Validators;

use App\Services\AI\AFOS\Compiler\Diagnostics\DiagnosticBag;
use App\Services\AI\AFOS\Compiler\Diagnostics\DiagnosticCode;
use App\Services\AI\AFOS\Ir\CameraIR;
use App\Services\AI\AFOS\Types\CameraMovementType;

/**
 * CameraIRValidator — validates CameraIR after Tier 2 passes run.
 *
 * Codes:
 *   AFOS1101 WARNING — focalLengthMm < 14mm: below Kling minimum
 *   AFOS1102 WARNING — focalLengthMm > 85mm: backend will override
 *   AFOS1103 WARNING — aperture ≤ 0: invalid optical value
 *   AFOS1104 HINT    — STATIC + non-empty velocityCurve: planner inconsistency
 *   AFOS1105 HINT    — telephoto (>85mm) + HANDHELD: extreme blur risk
 */
final class CameraIRValidator implements CameraStageValidator
{
    private const MIN_FOCAL_MM = 14;
    private const MAX_FOCAL_MM = 85;
    private const PASS         = 'CameraIRValidator';

    public function validate(CameraIR $ir, DiagnosticBag $bag): void
    {
        if ($ir->focalLengthMm < self::MIN_FOCAL_MM) {
            $bag->warn(
                "Focal length {$ir->focalLengthMm}mm is below Kling minimum (" . self::MIN_FOCAL_MM . "mm)",
                DiagnosticCode::FOCAL_BELOW_MINIMUM,
                self::PASS, 'focalLengthMm'
            );
        } elseif ($ir->focalLengthMm > self::MAX_FOCAL_MM) {
            $bag->warn(
                "Focal length {$ir->focalLengthMm}mm exceeds Kling maximum (" . self::MAX_FOCAL_MM . "mm) — backend will override",
                DiagnosticCode::FOCAL_EXCEEDS_MAXIMUM,
                self::PASS, 'focalLengthMm'
            );
        }

        if ($ir->aperture <= 0.0) {
            $bag->warn(
                "Aperture {$ir->aperture} is invalid (must be a positive f-stop value)",
                DiagnosticCode::APERTURE_INVALID,
                self::PASS, 'aperture'
            );
        }

        if ($ir->movementType === CameraMovementType::STATIC && !empty($ir->velocityCurve)) {
            $bag->hint(
                'STATIC movement has a non-empty velocity curve — planner inconsistency, curve will be ignored',
                DiagnosticCode::STATIC_WITH_VELOCITY,
                self::PASS, 'velocityCurve'
            );
        }

        if ($ir->focalLengthMm > self::MAX_FOCAL_MM && $ir->movementType === CameraMovementType::HANDHELD) {
            $bag->hint(
                "Telephoto ({$ir->focalLengthMm}mm) + HANDHELD combination risks significant motion blur",
                DiagnosticCode::TELEPHOTO_HANDHELD,
                self::PASS, 'focalLengthMm'
            );
        }
    }
}
