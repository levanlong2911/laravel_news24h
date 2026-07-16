<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Selection;

/**
 * Where a thing in the prompt came from (ADR-019 §7).
 *
 * This is evidence, not decoration: it is what makes "no layer has the right to
 * invent" auditable on a concrete prompt instead of only true-if-the-code-is-right.
 * A line whose origin is DEFAULT_SEMANTICS while claiming an article detail is a
 * self-proving bug.
 */
enum Origin: string
{
    /** The article said it. */
    case SHOT_TRUTH = 'shot_truth';

    /** The article was silent; Precedence took a default for the category. */
    case DEFAULT_SEMANTICS = 'default_semantics';
}
