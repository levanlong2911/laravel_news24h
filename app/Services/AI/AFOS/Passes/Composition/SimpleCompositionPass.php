<?php

namespace App\Services\AI\AFOS\Passes\Composition;

use App\Services\AI\AFOS\Creative\CinematographyProfile;
use App\Services\AI\AFOS\Creative\DirectorProfile;
use App\Services\AI\AFOS\Ir\CompositionIR;
use App\Services\AI\AFOS\Ir\ShotGoalIR;
use App\Services\AI\AFOS\Passes\Config\CompositionPassConfig;
use App\Services\AI\AFOS\Passes\PassParameterSchema;
use App\Services\AI\AFOS\Passes\ShotGoalToCompositionPass;
use App\Services\AI\AFOS\Planning\SimpleCompositionBuilder;

/**
 * SimpleCompositionPass — Phase A wrapper around SimpleCompositionBuilder.
 *
 * Exposes the builder as a named, parameterized pass so PassManager can
 * enumerate it, TraceCollector can log it, and Experience Engine can tune it.
 *
 * Key architectural invariant:
 *   This pass NEVER mutates DirectorProfile. Creative identity is immutable.
 *   Optimizer tuning is expressed through CompositionPassConfig exclusively.
 *   SimpleCompositionBuilder reads config biases alongside the profile.
 *
 * Phase B: replace with CompositionSolverPass (CSP-backed). Interface unchanged.
 */
final class SimpleCompositionPass implements ShotGoalToCompositionPass
{
    public function __construct(
        private readonly CompositionPassConfig $config = new CompositionPassConfig(),
    ) {}

    public function name(): string { return 'SimpleCompositionPass'; }

    /** @return PassParameterSchema[] */
    public function parameterSchema(): array
    {
        return CompositionPassConfig::schema();
    }

    public function parameters(): array
    {
        return $this->config->toArray();
    }

    public function run(ShotGoalIR $shot, DirectorProfile $director, CinematographyProfile $dp): CompositionIR
    {
        return (new SimpleCompositionBuilder($this->config))->build($shot, $director, $dp);
    }
}
