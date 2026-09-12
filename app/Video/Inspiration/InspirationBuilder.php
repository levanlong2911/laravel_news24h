<?php

declare(strict_types=1);

namespace App\Video\Inspiration;

use App\Video\Evidence\EvidenceIndex;

final class InspirationBuilder
{
    public function __construct(
        private readonly InspirationBriefValidator $validator = new InspirationBriefValidator,
    ) {
    }

    public function build(
        InspirationBrief $draft,
        CategoryCreativeProfile $profile,
        EvidenceIndex $index,
    ): InspirationBrief {
        $final = new InspirationBrief(
            articlePatterns: $draft->articlePatterns,
            articleFocus: $draft->articleFocus,
            sourceInsights: $this->profileCompatibleInsights($draft, $profile),
            excludedContext: $draft->excludedContext,
        );

        $violations = $this->validator->violations($final, $index, $profile);

        if ($violations !== []) {
            throw new InvalidInspirationBrief($violations);
        }

        return $final;
    }

    /**
     * @return list<SourceInsight>
     */
    private function profileCompatibleInsights(
        InspirationBrief $draft,
        CategoryCreativeProfile $profile,
    ): array {
        return array_values(array_filter(
            $draft->sourceInsights,
            static fn (SourceInsight $insight): bool => in_array(
                $insight->aspect,
                $profile->inspectionAspects,
                true,
            ),
        ));
    }
}
