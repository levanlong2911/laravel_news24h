<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Benchmark\Selection;

use App\Services\AI\FilmOS\Selection\ArticleModel;
use App\Services\AI\FilmOS\Selection\ArticleFact;
use App\Services\AI\FilmOS\Selection\BeatContext;
use App\Services\AI\FilmOS\Selection\ShotTruth;

/**
 * For every selectable fact no shot ever used, say which class its failure falls
 * into and show the minimal witness.
 *
 * This discharges ONE obligation: benchmark validity (ADR-020 §10.2). It answers
 * "is the coverage residual contaminated by a known mechanism", and it is allowed
 * to answer nothing else.
 *
 * What it must never do, because each would spend this proof on another layer:
 *   - cluster failures into groups          (that is Discovery, a later obligation)
 *   - name a cause                          (ENTITY_NEVER_STAGED is not "why")
 *   - conclude anything about the ontology  (that needs ontology's own evidence)
 *   - suggest a module, or say "locations are the problem"
 *
 * It says: fact X, class Y, witness Z. Someone else decides what that means.
 */
final class EligibilityAttributor
{
    /**
     * @param BeatContext[] $contexts
     * @param ShotTruth[] $truths
     * @return Attribution[]
     */
    public function attribute(ArticleModel $model, array $contexts, array $truths): array
    {
        $used = [];
        foreach ($truths as $truth) {
            foreach ($truth->factIds() as $id) {
                $used[$id] = true;
            }
        }

        $everStaged = [];
        foreach ($contexts as $context) {
            foreach ($context->visibleEntities as $entity) {
                $everStaged[$entity] = true;
            }
        }

        $out = [];
        foreach ($model->selectableFacts() as $fact) {
            if (isset($used[$fact->id])) {
                continue;
            }
            $out[] = $this->classify($fact, $contexts, $everStaged);
        }

        return $out;
    }

    /**
     * Gather every class that applies, then take the most upstream one.
     *
     * Written this way, not as an ordered run of `return`s, so that precedence is
     * `AttributionClass::precedence()` — one declared invariant — rather than an
     * accident of statement order that a later edit could reverse in silence.
     * More than one class genuinely can apply at once: an entity staged nowhere
     * also means no beat ever held the full set.
     *
     * @param array<string, true> $everStaged
     * @param BeatContext[] $contexts
     */
    private function classify(ArticleFact $fact, array $contexts, array $everStaged): Attribution
    {
        /** @var Attribution[] $candidates */
        $candidates = [];

        $absent = array_values(array_filter(
            $fact->entityRefs,
            static fn (string $ref): bool => !isset($everStaged[$ref]),
        ));
        if ($absent !== []) {
            $candidates[] = new Attribution($fact->id, AttributionClass::ENTITY_NEVER_STAGED, $absent);
        }

        $eligibleIn = [];
        foreach ($contexts as $context) {
            if ($fact->isVisibleIn($context->visibleEntities)) {
                $eligibleIn[] = $context->beat;
            }
        }

        if ($eligibleIn === []) {
            // No single entity is at fault, so the combination is the evidence.
            $candidates[] = new Attribution($fact->id, AttributionClass::ENTITIES_NEVER_CO_PRESENT, $fact->entityRefs);
        } else {
            // The policy saw it and passed. The only class that says anything about
            // the policy at all — the beats it could have used are the evidence.
            $candidates[] = new Attribution($fact->id, AttributionClass::POLICY_DECLINED, $eligibleIn);
        }

        usort(
            $candidates,
            static fn (Attribution $a, Attribution $b): int => $a->class->precedence() <=> $b->class->precedence(),
        );

        return $candidates[0];
    }
}
