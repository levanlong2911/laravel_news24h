<?php

namespace App\Services\AI\Provider\Dto;

/**
 * Provider-agnostic video generation request.
 * Contains only the fields the pipeline knows about — no model names, no provider config.
 * Each provider adapter adds its own provider-specific fields (model, mode, cfgScale, etc.).
 */
final class RenderVideoRequest
{
    public function __construct(
        public readonly string $prompt,
        public readonly string $negativePrompt,
        public readonly int    $durationSeconds,
        public readonly string $aspectRatio,     // '16:9' | '9:16' | '1:1'
    ) {}
}
