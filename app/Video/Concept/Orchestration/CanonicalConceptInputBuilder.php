<?php

declare(strict_types=1);

namespace App\Video\Concept\Orchestration;

use App\Models\AiPhase;
use App\Video\Article\ArticleNormalizer;
use App\Video\Article\RawArticle;
use App\Video\Inspiration\ClaudeInspirationAnalyst;
use App\Video\Inspiration\InspirationResult;

final class CanonicalConceptInputBuilder
{
    public function __construct(
        private readonly ArticleNormalizer $normalizer,
        private readonly ClaudeInspirationAnalyst $analyst,
    ) {
    }

    public function articleBlock(RawArticle $article): string
    {
        return $this->analyst->articleBlock($this->normalizer->normalize($article));
    }

    /** @return array{model: string, provider_model: ?string, temperature: float, max_tokens: int} */
    public function inspirationSettings(): array
    {
        return $this->analyst->settings();
    }

    /** @param ?\Closure(array<string, mixed>): (bool|null) $onProgress */
    public function buildInspiration(AiPhase $prompt, string $articleBlock, ?\Closure $onProgress = null): InspirationResult
    {
        return $this->analyst->analyze($prompt, $articleBlock, $onProgress);
    }
}
