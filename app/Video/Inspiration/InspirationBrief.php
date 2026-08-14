<?php

namespace App\Video\Inspiration;

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

    /** @return list<string> */
    public function missingAspects(CategoryCreativeProfile $profile): array
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
    public function toArray(CategoryCreativeProfile $profile): array
    {
        return [
            'article_patterns' => $this->articlePatterns,
            'article_focus' => $this->articleFocus,
            'source_insights' => array_map(fn (SourceInsight $item) => $item->toArray(), $this->sourceInsights),
            'excluded_context' => array_map(fn (ExcludedContext $item) => $item->toArray(), $this->excludedContext),
            'missing_design_aspects' => $this->missingAspects($profile),
        ];
    }
}
