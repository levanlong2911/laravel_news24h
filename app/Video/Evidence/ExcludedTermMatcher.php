<?php

declare(strict_types=1);

namespace App\Video\Evidence;

final class ExcludedTermMatcher
{
    /** @param list<string> $excludedValues */
    public function carries(string $text, array $excludedValues): bool
    {
        $haystack = mb_strtolower($text);

        foreach ($excludedValues as $value) {
            if ($this->containsTerm($haystack, $value)) {
                return true;
            }
        }

        return false;
    }

    public function containsTerm(string $haystack, string $term): bool
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
