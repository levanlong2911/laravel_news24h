<?php

namespace App\Services\AI\PromptAST\Blocks;

/**
 * Technical visual quality block.
 *
 * Covers camera-physical qualities: sharpness tier, motion blur amount,
 * depth-of-field focus technique, and acceleration profile.
 *
 * Sprint 7: qualityTier will be derived from VideoProject.quality setting.
 * For now defaults to 'photoreal' (sports footage is always maximum quality).
 */
final class StyleBlock
{
    public function __construct(
        /** Quality tier: photoreal | high | medium | standard */
        public readonly string $qualityTier,
        /** Normalized motion blur: 0.0 (none) – 1.0 (heavy) */
        public readonly float  $motionBlur,
        /** Rack focus pull during the shot */
        public readonly bool   $rackFocus,
        /** Acceleration profile: linear | ease-in | ease-out | snap */
        public readonly string $acceleration,
    ) {}
}
