<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\IR;

/**
 * One article-derived visual detail the shot should contain, with its priority.
 *
 * Sourced from facts[].visual_hint (e.g. "two defenders converging", "deep
 * spiral against evening sky") — accurate visual truth extracted from the
 * article, never invented. The compiler ranks these by relevance; the renderer
 * phrases them. Neutral (Prompting/IR) so no vendor or benchmark coupling.
 *
 * Immutable.
 */
final class KeyVisual
{
    public function __construct(
        public readonly string          $hint,
        public readonly VisualRelevance $relevance,
    ) {}
}
