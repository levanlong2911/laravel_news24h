<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Selection;

/**
 * What this shot is allowed to say, and what it looks at (ADR-019 §3).
 *
 * Shot-scoped. It may never contain a fact the Article Model does not: Selection
 * subsets, it never paraphrases. Being an inspectable artifact is the point —
 * "why did the payoff say `vertical bow`?" is answerable from this object alone,
 * without reading the planner, the formatter, or the prompt.
 */
final class ShotTruth
{
    /** @param SelectedFact[] $facts */
    public function __construct(
        public readonly string $beat,
        public readonly string $focusEntity,
        public readonly array $facts,
    ) {}

    /** @return string[] */
    public function factIds(): array
    {
        return array_map(static fn (SelectedFact $f): string => $f->factId, $this->facts);
    }
}
