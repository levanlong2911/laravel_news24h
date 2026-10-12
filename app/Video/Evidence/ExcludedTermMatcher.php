<?php

declare(strict_types=1);

namespace App\Video\Evidence;

final class ExcludedTermMatcher
{
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
