<?php

namespace App\Services\AI\PromptCompiler\PromptDocument;

/**
 * Provider-agnostic intermediate representation of a visual prompt.
 *
 * Built by PromptDocumentBuilder from Compact DSL + Knowledge Libraries.
 * Rendered to a provider-specific string by FluxRenderer / KlingRenderer / KenBurnsRenderer.
 *
 * Adding a new provider = adding a new Renderer. No other code changes.
 */
final class PromptDocument
{
    public function __construct(
        public readonly CameraBlock       $camera,
        public readonly SubjectBlock      $subject,
        public readonly EnvironmentBlock  $environment,
        public readonly EmotionBlock      $emotion,
        public readonly QualityBlock      $quality,
        public readonly ?NegativeBlock    $negative    = null,
        public readonly ?ContinuityBlock  $continuity  = null,
    ) {}
}
