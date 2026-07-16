<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Selection;

/**
 * The first selection policy that has ever existed on this pipeline.
 *
 * It is written from stated principles, as if no benchmark existed. It has never
 * been shown a reference selection and cannot be: `select()` takes no reference.
 * Where its principles are wrong, it should score badly — that is the measurement,
 * not a failure. Tuning it toward the authored answer would make Phase 1A prove
 * nothing, so the principles below are the whole of it and each is defensible
 * without looking at a single benchmark:
 *
 *  1. ENTITY SCOPE. A fact may be spoken only where every entity it describes is
 *     on screen. Saying "two defenders converge" over one defender is a lie the
 *     footage contradicts. This is the only subsetting signal the Article Model
 *     honestly carries, and it is why `entity_refs` had to exist.
 *
 *  2. THE CAMERA HOLDS THE SUBJECT. The article is about one thing; a film of it
 *     looks at that thing. Where the subject is somehow absent, hold whatever is
 *     present rather than invent a target.
 *
 *  3. NO DISTRIBUTION — DELIBERATELY. Every eligible fact is taken, every beat.
 *     There is no anti-starvation, no cap, no spreading. ADR-019 §6.3 defers
 *     Coverage, so this policy must NOT quietly implement it: the point is to
 *     measure what happens without it. Expect repetition. Reporting that number
 *     honestly is what earns Coverage its implementation later.
 *
 * Principle 2 is the one most likely to be wrong, and knowingly so: a payoff that
 * follows a thrown ball rather than the thrower is a real shot, and nothing in the
 * Article Model expresses it, because the model has entities and facts but no
 * action structure. If that mismatch shows up in the score, the finding is about
 * the MODEL, not about this class.
 */
final class EntityScopedSelectionPolicy implements SelectionPolicy
{
    public function select(ArticleModel $model, BeatContext $context): ShotTruth
    {
        $facts = [];

        foreach ($model->facts as $fact) {
            if (!$fact->isSelectable() || !$fact->isVisibleIn($context->visibleEntities)) {
                continue;
            }
            $facts[] = new SelectedFact(
                factId:     $fact->id,
                entityRefs: $fact->entityRefs,
                visualHint: (string) $fact->visualHint,
                origin:     Origin::SHOT_TRUTH,   // it came from the article, by construction
            );
        }

        return new ShotTruth(
            beat:        $context->beat,
            focusEntity: $this->focus($model, $context),
            facts:       $facts,
        );
    }

    /**
     * Principle 2.
     *
     * The fallback branch is not reasoning and must not be read as a success when
     * it happens to agree with an author: holding "whatever is present" is the
     * honest refusal to invent a target, nothing more.
     */
    private function focus(ArticleModel $model, BeatContext $context): string
    {
        if ($context->sees($model->topicEntity)) {
            return $model->topicEntity;
        }
        return $context->visibleEntities[0] ?? $model->topicEntity;
    }
}
