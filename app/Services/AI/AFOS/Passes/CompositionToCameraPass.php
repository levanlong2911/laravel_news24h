<?php

namespace App\Services\AI\AFOS\Passes;

use App\Services\AI\AFOS\Creative\CinematographyProfile;
use App\Services\AI\AFOS\Creative\DirectorProfile;
use App\Services\AI\AFOS\Ir\CameraIR;
use App\Services\AI\AFOS\Ir\CompositionIR;

/**
 * CompositionToCameraPass — interface for the second pass tier.
 *
 * Translates CompositionIR (spatial layout) → CameraIR (optics + movement).
 * Phase A: SimpleCameraBuilder implements this.
 * Phase B: CameraPlanner (geometric derivation) implements this.
 *
 * Implementations must NOT touch ShotGoalIR or Backend.
 * Implementations must NOT read configuration outside $parameters.
 */
interface CompositionToCameraPass
{
    public function name(): string;

    /**
     * Typed schema describing each tunable parameter's valid range and type.
     * Experience Engine reads this to constrain its search space.
     *
     * @return PassParameterSchema[]
     */
    public function parameterSchema(): array;

    /** Current parameter values (snapshot for trace logging). */
    public function parameters(): array;

    public function run(
        CompositionIR         $composition,
        DirectorProfile       $director,
        CinematographyProfile $dp,
    ): CameraIR;
}
