<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Adapter\Format;

/**
 * How much prompt a provider will actually read well.
 *
 * A vendor fact, never a story fact — which is why nothing upstream of the
 * adapter is allowed to know it exists. The number covers the FINAL prompt,
 * boilerplate included: a budget that only limits "content" while fixed
 * overhead rides free is not a budget.
 *
 * Immutable.
 */
final class Budget
{
    private function __construct(public readonly int $maxWords) {}

    /** Kling degrades on long prompts; ~200 words is where it holds together. */
    public static function kling(): self
    {
        return new self(200);
    }

    public static function ofWords(int $maxWords): self
    {
        return new self(max(1, $maxWords));
    }
}
