<?php

namespace App\Services\AI\PromptCompiler;

/**
 * Adjusts how a PromptDocument is rendered for a specific visual style.
 *
 * The Decision Engine (Phase B) selects the profile based on category/theme/shot type.
 * Each Renderer reads the profile's additions and negatives before rendering.
 *
 * Adding support for a new model (Veo, Runway, Imagen…) means:
 *   1. New Renderer class
 *   2. New match arm in Compiler
 *   3. Optionally: new RenderProfile presets for that model's strengths
 *   — No Planner or PromptDocument changes needed.
 */
final class RenderProfile
{
    // Named presets — Decision Engine picks one per shot
    public const CINEMATIC    = 'cinematic';
    public const LUXURY       = 'luxury';
    public const MACRO        = 'macro';
    public const PRODUCT      = 'product';
    public const EDITORIAL    = 'editorial';
    public const CONCEPT_ART  = 'concept_art';
    public const PHOTO_REAL   = 'photo_real';
    public const HYPER_REAL   = 'hyper_real';

    public function __construct(
        public readonly string $name,
        /** Extra quality/style phrases appended to QualityBlock by Renderer. */
        public readonly array  $styleAdditions = [],
        /** Extra negative terms for models that support negative prompts. */
        public readonly array  $negativeAdditions = [],
    ) {}

    public static function default(): self
    {
        return new self(self::CINEMATIC);
    }

    public static function luxury(): self
    {
        return new self(
            name:            self::LUXURY,
            styleAdditions:  [
                'Premium materials.',
                'Architectural photography.',
                'Rich reflections.',
                'High-end interior lighting.',
                'Magazine quality.',
            ],
            negativeAdditions: ['cheap', 'plastic', 'low-end'],
        );
    }

    public static function macro(): self
    {
        return new self(
            name:            self::MACRO,
            styleAdditions:  [
                'Extreme detail rendering.',
                'Shallow depth of field.',
                'Bokeh background.',
                'Texture close-up.',
            ],
        );
    }

    public static function product(): self
    {
        return new self(
            name:           self::PRODUCT,
            styleAdditions: [
                'Clean product photography.',
                'Studio-grade lighting.',
                'Commercial quality.',
                'E-commerce ready.',
            ],
        );
    }
}
