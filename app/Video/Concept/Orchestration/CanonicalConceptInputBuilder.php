<?php

declare(strict_types=1);

namespace App\Video\Concept\Orchestration;

use App\Video\Article\ArticleNormalizer;
use App\Video\Article\RawArticle;
use App\Video\Concept\ConceptInput;
use App\Video\Extraction\Extractor;
use App\Video\Gatekeeper\EvidenceGatekeeper;
use App\Video\Inspiration\CategoryCreativeProfile as InspirationProfile;
use App\Video\Inspiration\ClaudeInspirationAnalyst;
use App\Video\Inspiration\InspirationBrief;
use App\Video\Inspiration\InspirationBuilder;
use App\Video\Inspiration\InspirationResult;
use App\Video\Inspiration\InvalidInspirationBrief;
use App\Video\Profiles\CategoryCreativeProfile as CanonicalProfile;

final class CanonicalConceptInputBuilder
{
    public function __construct(
        private readonly ArticleNormalizer $normalizer,
        private readonly Extractor $extractor,
        private readonly EvidenceGatekeeper $gatekeeper,
        private readonly ClaudeInspirationAnalyst $analyst,
        private readonly InspirationBuilder $inspirationBuilder,
    ) {
    }

    /**
     * @param  array<string,mixed>  $projectRequirements
     */
    public function build(
        RawArticle $article,
        string $objectType,
        InspirationProfile $inspirationProfile,
        CanonicalProfile $canonicalProfile,
        array $projectRequirements = [],
    ): ConceptInput {
        $brief = $this->buildInspiration($article, $inspirationProfile)->brief;

        return new ConceptInput(
            objectType: $objectType,
            inspiration: $brief,
            profile: $canonicalProfile,
            projectRequirements: $projectRequirements,
        );
    }

    /**
     * @param  array<string,mixed>  $projectRequirements
     */
    public function fromBrief(
        string $objectType,
        InspirationBrief $brief,
        CanonicalProfile $canonicalProfile,
        array $projectRequirements = [],
    ): ConceptInput {
        return new ConceptInput(
            objectType: $objectType,
            inspiration: $brief,
            profile: $canonicalProfile,
            projectRequirements: $projectRequirements,
        );
    }

    public function buildInspiration(
        RawArticle $article,
        InspirationProfile $profile,
    ): InspirationResult {
        $index = $this->normalizer->normalize($article);

        $extraction = $this->extractor->extract($article, $index);
        $this->gatekeeper->verify($extraction->candidates, $index);

        $draft = $this->analyst->analyze($article, $profile);

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
