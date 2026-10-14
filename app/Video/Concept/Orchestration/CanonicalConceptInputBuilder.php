<?php

declare(strict_types=1);

namespace App\Video\Concept\Orchestration;

use App\Video\Article\ArticleNormalizer;
use App\Video\Article\RawArticle;
use App\Video\Inspiration\CategoryCreativeProfile as InspirationProfile;
use App\Video\Inspiration\ClaudeInspirationAnalyst;
use App\Video\Inspiration\InspirationBuilder;
use App\Video\Inspiration\InspirationResult;
use App\Video\Inspiration\InvalidInspirationBrief;

final class CanonicalConceptInputBuilder
{
    public function __construct(
        private readonly ArticleNormalizer $normalizer,
        private readonly ClaudeInspirationAnalyst $analyst,
        private readonly InspirationBuilder $inspirationBuilder,
    ) {
    }

    /** @return array{profile: array<string, string>, analyst: array<string, ?string>} */
    public function inspirationFingerprint(InspirationProfile $profile): array
    {
        return [
            'profile' => $profile->inspirationFingerprint(),
            'analyst' => $this->analyst->fingerprint(),
        ];
    }

    public function buildInspiration(
        RawArticle $article,
        InspirationProfile $profile,
    ): InspirationResult {
        $index = $this->normalizer->normalize($article);
        $draft = $this->analyst->analyze($article, $profile, $index);

        try {
            $brief = $this->inspirationBuilder->build(
                draft: $draft->brief,
                profile: $profile,
                index: $index,
            );
        } catch (InvalidInspirationBrief $exception) {
            throw new InvalidInspirationBrief(
                $exception->violations,
                $draft->rawResponse,
                $draft->usage,
            );
        }

        return new InspirationResult(
            brief: $brief,
            attempts: $draft->attempts,
            rawResponse: $draft->rawResponse,
            usage: $draft->usage,
        );
    }
}
