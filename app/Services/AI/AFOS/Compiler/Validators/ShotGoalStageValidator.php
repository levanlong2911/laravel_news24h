<?php

namespace App\Services\AI\AFOS\Compiler\Validators;

use App\Services\AI\AFOS\Compiler\Diagnostics\DiagnosticBag;
use App\Services\AI\AFOS\Ir\ShotGoalIR;

/**
 * ShotGoalStageValidator — validates ShotGoalIR before Tier 1 passes run.
 *
 * Implementations append diagnostics to $bag and return nothing.
 * PassManager aborts compilation if the bag has errors after all shot validators run.
 */
interface ShotGoalStageValidator extends IRValidator
{
    public function validate(ShotGoalIR $ir, DiagnosticBag $bag): void;
}
