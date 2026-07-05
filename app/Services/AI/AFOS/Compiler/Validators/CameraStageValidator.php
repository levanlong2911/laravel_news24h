<?php

namespace App\Services\AI\AFOS\Compiler\Validators;

use App\Services\AI\AFOS\Compiler\Diagnostics\DiagnosticBag;
use App\Services\AI\AFOS\Ir\CameraIR;

/**
 * CameraStageValidator — validates CameraIR after Tier 2 passes run.
 *
 * Non-blocking: errors from camera validation are demoted to warnings
 * because CameraIR is backend-adjustable (PassManager already clamps
 * focal length for backends that don't support lens data).
 */
interface CameraStageValidator extends IRValidator
{
    public function validate(CameraIR $ir, DiagnosticBag $bag): void;
}
