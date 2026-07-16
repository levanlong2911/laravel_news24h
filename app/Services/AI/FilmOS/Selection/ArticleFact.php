<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Selection;

/**
 * One filmable claim the article makes, and which entities it describes.
 *
 * NOTE WHAT IS ABSENT: the fact's `text`. Selection must never parse natural
 * language (ADR-019 invariant), so the model does not carry any for it to parse.
 * Reading `text` is the extractor's job, upstream, in Phase 2. `visualHint` is
 * present because it is *passed through* to the formatter, never inspected here.
 *
 * `entityRefs` is identity, not staging: "F6 describes Nebula" is true in every
 * shot, including the shots Nebula is absent from.
 */
final class ArticleFact
{
    /** @param string[] $entityRefs */
    public function __construct(
        public readonly string $id,
        public readonly array $entityRefs,
        public readonly string $category,
        public readonly FactRelevance $relevance,
        public readonly float $confidence,
        public readonly ?string $visualHint = null,
    ) {}

    /**
     * Can a camera carry this at all?
     *
     * Two independent reasons a fact is unfilmable, and both are the article's
     * property, not a shot's: it offers no visual hint (nothing to photograph —
     * "has never been chartered" is a fact about paperwork), or it is LOW
     * relevance (photographable, not worth a word of the budget).
     *
     * This is the denominator Coverage is measured against. Counting unfilmable
     * facts would report starvation for facts that SHOULD go unused.
     */
    public function isSelectable(): bool
    {
        return $this->visualHint !== null
            && $this->visualHint !== ''
            && $this->relevance !== FactRelevance::LOW;
    }

    /**
     * A fact is only filmable when EVERY entity it describes is on screen.
     * "Pass rush collapses the pocket from both edges" cannot be shot with one
     * rusher in frame; it is a claim about both.
     *
     * @param string[] $visibleEntities
     */
    public function isVisibleIn(array $visibleEntities): bool
    {
        foreach ($this->entityRefs as $ref) {
            if (!in_array($ref, $visibleEntities, true)) {
                return false;
            }
        }
        return $this->entityRefs !== [];
    }
}
