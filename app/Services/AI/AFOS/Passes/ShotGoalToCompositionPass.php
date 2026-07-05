<?php

namespace App\Services\AI\AFOS\Passes;

use App\Services\AI\AFOS\Creative\CinematographyProfile;
use App\Services\AI\AFOS\Creative\DirectorProfile;
use App\Services\AI\AFOS\Ir\CompositionIR;
use App\Services\AI\AFOS\Ir\ShotGoalIR;

/**
 * ShotGoalToCompositionPass — interface for the first pass tier.
 *
 * Translates ShotGoalIR (WHAT to show) → CompositionIR (WHERE things are).
 * Phase A: SimpleCompositionBuilder implements this.
 * Phase B: CompositionSolver (CSP) implements this.
 *
 * Implementations must NOT touch CameraIR or Backend.
 * Implementations must NOT read configuration outside $parameters.
 */
interface ShotGoalToCompositionPass
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
        ShotGoalIR            $shot,
        DirectorProfile       $director,
        CinematographyProfile $dp,
    ): CompositionIR;
}
