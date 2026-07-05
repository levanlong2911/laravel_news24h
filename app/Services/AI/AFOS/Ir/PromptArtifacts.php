<?php

namespace App\Services\AI\AFOS\Ir;

/**
 * PromptArtifacts — backend-specific rendered outputs for one shot.
 *
 * When multiple backends are supported (Kling, Veo, Runway, Wan), each
 * produces its own PromptArtifacts from the same SemanticState.
 * PromptIRSnapshot maps backend → PromptArtifacts; SemanticState is shared.
 */
final class PromptArtifacts
{
    public function __construct(
        public readonly string  $compiledPrompt,
        public readonly string  $backend        = 'kling',
        public readonly ?string $negativePrompt = null,
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'compiled_prompt' => $this->compiledPrompt,
            'backend'         => $this->backend,
            'negative_prompt' => $this->negativePrompt,
        ], fn($v) => $v !== null);
    }
}
