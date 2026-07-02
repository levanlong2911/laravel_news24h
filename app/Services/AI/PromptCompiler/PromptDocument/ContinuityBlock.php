<?php

namespace App\Services\AI\PromptCompiler\PromptDocument;

/**
 * Carries a visual anchor from the first shot of a scene into subsequent shots.
 * Populated by ContinuityEngine; null on the first shot of every scene.
 *
 * Example anchor: "same quarterback in green-gold jersey, packed winter stadium, warm golden light"
 */
final class ContinuityBlock
{
    public function __construct(
        public readonly string $anchor,
    ) {}
}
