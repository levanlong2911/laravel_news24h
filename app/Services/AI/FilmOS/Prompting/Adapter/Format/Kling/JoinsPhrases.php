<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Adapter\Format\Kling;

/**
 * "a, b and c" — English list punctuation, shared by the formatters that build
 * lists. A trait rather than a base class: formatters are siblings, not a family,
 * and none of them should be able to inherit another's wording.
 */
trait JoinsPhrases
{
    /** @param string[] $items */
    private function join(array $items): string
    {
        if (count($items) <= 1) {
            return implode('', $items);
        }
        $last = array_pop($items);
        return implode(', ', $items) . ' and ' . $last;
    }

    private function sentence(string $text): string
    {
        return ucfirst(rtrim(trim($text), '.')) . '.';
    }
}
