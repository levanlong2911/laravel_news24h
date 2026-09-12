<?php

declare(strict_types=1);

namespace App\Video\Prompt;

use App\Video\Concept\Handoff\CompiledAnchorPrompt;

final class GeometryPromptResult
{
    public function __construct(
        public readonly CompiledAnchorPrompt $compiled,
        public readonly string $authorModel,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
    ) {}

    /**
     * Du de dung lai CompiledAnchorPrompt ma khong goi model lan nua.
     *
     * @return array<string, mixed>
     */
    public function toStorage(): array
    {
        return [
            'prompt' => $this->compiled->prompt,
            'prompt_sha256' => $this->compiled->promptHash,
            'prompt_version' => $this->compiled->promptVersion,
            'author_model' => $this->authorModel,
        ];
    }
}
