<?php

namespace App\Video\Inspiration;

use App\Video\Profiles\CategoryCreativeProfile as ObjectProfile;

final class InspirationBrief
{
    /**
     * @param  list<string>  $articlePatterns
     * @param  list<SourceInsight>  $sourceInsights
     * @param  list<ExcludedContext>  $excludedContext
     */
    public function __construct(
        public readonly array $articlePatterns,
        public readonly string $articleFocus,
        public readonly array $sourceInsights,
        public readonly array $excludedContext,
    ) {}

    /**
     * @return list<string>
     */
    public function uncoveredAspects(CategoryCreativeProfile|ObjectProfile $profile): array
    {
        $found = array_fill_keys(array_map(
            fn (SourceInsight $insight) => $insight->aspect,
            $this->sourceInsights,
        ), true);

        return array_values(array_filter(
            $profile->inspectionAspects,
            fn (string $aspect) => ! isset($found[$aspect]),
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(CategoryCreativeProfile|ObjectProfile $profile): array
    {
        return [
            'article_patterns' => $this->articlePatterns,
            'article_focus' => $this->articleFocus,
            'source_insights' => array_map(fn (SourceInsight $item) => $item->toArray(), $this->sourceInsights),
            'excluded_context' => array_map(fn (ExcludedContext $item) => $item->toArray(), $this->excludedContext),
            'uncovered_aspects' => $this->uncoveredAspects($profile),
        ];
    }

    /** @return list<string> */
    public function coveredAspects(): array
    {
        return array_values(array_unique(array_map(
            fn (SourceInsight $insight) => $insight->aspect,
            $this->sourceInsights,
        )));
    }

    public function hasSourceAspect(string $aspect): bool
    {
        return in_array($aspect, $this->coveredAspects(), true);
    }

    /**
     * Tach hai nguon: `inspiration` la thu Haiku tra ve, `coverage` la thu
     * Laravel tinh tu profile. Nhin payload la biet ngay phan nao do ai chiu
     * trach nhiem.
     *
     * @return array<string, mixed>
     */
    public function toConceptInput(CategoryCreativeProfile|ObjectProfile $profile): array
    {
        return [
            'inspiration' => [
                'article_patterns' => $this->articlePatterns,
                'article_focus' => $this->articleFocus,
                'source_insights' => array_map(fn (SourceInsight $item) => $item->toArray(), $this->sourceInsights),
                'excluded_context' => array_map(fn (ExcludedContext $item) => $item->toArray(), $this->excludedContext),
            ],

            'coverage' => [
                'uncovered_aspects' => $this->uncoveredAspects($profile),
            ],
        ];
    }
}
