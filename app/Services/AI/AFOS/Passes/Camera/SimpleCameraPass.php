<?php

namespace App\Services\AI\AFOS\Passes\Camera;

use App\Services\AI\AFOS\Creative\CinematographyProfile;
use App\Services\AI\AFOS\Creative\DirectorProfile;
use App\Services\AI\AFOS\Ir\CameraIR;
use App\Services\AI\AFOS\Ir\CompositionIR;
use App\Services\AI\AFOS\Passes\CompositionToCameraPass;
use App\Services\AI\AFOS\Passes\Config\CameraPassConfig;
use App\Services\AI\AFOS\Passes\PassParameterSchema;
use App\Services\AI\AFOS\Planning\SimpleCameraBuilder;

/**
 * SimpleCameraPass — Phase A wrapper around SimpleCameraBuilder.
 *
 * Exposes the builder as a named, parameterized pass.
 *
 * Key architectural invariant:
 *   This pass NEVER mutates DirectorProfile or CinematographyProfile.
 *   Optimizer tuning is expressed through CameraPassConfig exclusively.
 *   SimpleCameraBuilder reads config alongside the profile.
 *
 * Phase B: replace with CameraGeometryPass (full geometric derivation). Interface unchanged.
 */
final class SimpleCameraPass implements CompositionToCameraPass
{
    public function __construct(
        private readonly CameraPassConfig $config = new CameraPassConfig(),
    ) {}

    public function name(): string { return 'SimpleCameraPass'; }

    /** @return PassParameterSchema[] */
    public function parameterSchema(): array
    {
        return CameraPassConfig::schema();
    }

    public function parameters(): array
    {
        return $this->config->toArray();
    }

    public function run(CompositionIR $composition, DirectorProfile $director, CinematographyProfile $dp): CameraIR
    {
        return (new SimpleCameraBuilder($this->config))->build($composition, $director, $dp);
    }
}
