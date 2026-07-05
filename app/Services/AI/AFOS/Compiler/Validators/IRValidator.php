<?php

namespace App\Services\AI\AFOS\Compiler\Validators;

/**
 * IRValidator — marker interface for all AFOS compiler validators.
 *
 * Stage-specific sub-interfaces carry type-safe validate() signatures:
 *   ShotGoalStageValidator  validate(ShotGoalIR, DiagnosticBag)
 *   CameraStageValidator    validate(CameraIR,   DiagnosticBag)
 *
 * AfosPassManager routes validators by instanceof — each stage's validators
 * receive exactly the IR type they expect, with no casting or dual methods.
 */
interface IRValidator {}
