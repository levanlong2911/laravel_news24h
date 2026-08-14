<?php

namespace App\Video\Inspiration;

use App\Video\Evidence\EvidenceIndex;

final class InspirationBriefValidator
{
    /** @return list<string> */
    public function violations(
        InspirationBrief $brief,
        EvidenceIndex $index,
        CategoryCreativeProfile $profile,
    ): array {
        $violations = [];

        foreach ($brief->articlePatterns as $pattern) {
            if (! in_array($pattern, $profile->articlePatterns, true)) {
                $violations[] = "article pattern {$pattern} is not allowed";
            }
        }
        if (count($brief->articlePatterns) !== count(array_unique($brief->articlePatterns))) {
            $violations[] = 'article patterns must not contain duplicates';
        }

        $excludedValues = [];
        foreach ($brief->excludedContext as $item) {
            if (! in_array($item->type, $profile->excludedContextTypes, true)) {
                $violations[] = "excluded context type {$item->type} is not allowed";
            }
            if (! $index->has($item->value)) {
                $violations[] = "excluded context value for {$item->type} is not present in the article";
            }
            $excludedValues[] = mb_strtolower($item->value);
        }

        if ($this->carriesExcluded($brief->articleFocus, $excludedValues)) {
            $violations[] = 'article_focus contains excluded identity context';
        }

        foreach ($brief->sourceInsights as $position => $insight) {
            if (! in_array($insight->aspect, $profile->inspectionAspects, true)) {
                $violations[] = "source_insights[{$position}] aspect {$insight->aspect} is not allowed";
            }

            foreach ($insight->sourceQuotes as $quotePosition => $quote) {
                if (! $index->has($quote)) {
                    $violations[] = "source_insights[{$position}].source_quotes[{$quotePosition}] is not present in the article";
                }
            }

            if ($this->carriesExcluded($insight->summary, $excludedValues)) {
                $violations[] = "source_insights[{$position}] contains excluded identity context";
            }
        }

        return array_values(array_unique($violations));
    }

    /** @param  list<string>  $excludedValues */
    private function carriesExcluded(string $text, array $excludedValues): bool
    {
        $haystack = mb_strtolower($text);

        foreach ($excludedValues as $value) {
            if ($this->containsTerm($haystack, $value)) {
                return true;
            }
        }

        return false;
    }

    private function containsTerm(string $haystack, string $term): bool
    {
        if ($term === '') {
            return false;
        }

        return preg_match(
            '/(?<![\pL\pN])'.preg_quote($term, '/').'(?![\pL\pN])/u',
            $haystack,
        ) === 1;
    }
}
