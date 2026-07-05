<?php

namespace App\Services\AI\AFOS\Passes\Config;

use App\Services\AI\AFOS\Passes\PassParameterSchema;

/**
 * CameraPassConfig — typed configuration for CompositionToCameraPass implementations.
 *
 * Experience Engine tunes these values, not the DirectorProfile or CinematographyProfile.
 * Those profiles describe the director's and DP's creative identities (immutable per shot).
 * CameraPassConfig describes the optimizer's learned adjustments.
 *
 * lensMmOverride: when > 0, forces a specific focal length regardless of
 *   what the camera builder would otherwise derive from composition + profile.
 *   Experience Engine learns whether specific domains benefit from specific lenses
 *   (e.g. luxury interiors: 50mm consistently outperforms 35mm).
 *
 * motionBias: additive offset to director.motionWeight.
 *   Prevents high-observation shots from always collapsing to STATIC.
 *   Experience Engine learns whether more motion improves retention for this domain.
 */
final class CameraPassConfig
{
    public function __construct(
        public readonly int   $lensMmOverride = 0,    // 0 = let builder decide
        public readonly float $motionBias     = 0.0,  // additive to director.motionWeight
        public readonly float $heightBias     = 0.0,  // reserved for Phase B height adjustment
    ) {}

    public static function defaults(): self
    {
        return new self();
    }

    /** @return PassParameterSchema[] */
    public static function schema(): array
    {
        return [
            new PassParameterSchema(
                'lensMmOverride', 'int', 0.0, 200.0, 0.0,
                'Force a specific focal length in mm (0 = builder decides). Experience Engine may lock to domain-optimal lens.'
            ),
            new PassParameterSchema(
                'motionBias', 'float', 0.0, 0.5, 0.0,
                'Additive offset to director.motionWeight — nudges toward dynamic moves. Tuned per domain.'
            ),
            new PassParameterSchema(
                'heightBias', 'float', -1.0, 1.0, 0.0,
                'Reserved: camera height adjustment bias (Phase B). Logged from Phase A.'
            ),
        ];
    }

    public function toArray(): array
    {
        return [
            'lensMmOverride' => $this->lensMmOverride,
            'motionBias'     => $this->motionBias,
            'heightBias'     => $this->heightBias,
        ];
    }
}
