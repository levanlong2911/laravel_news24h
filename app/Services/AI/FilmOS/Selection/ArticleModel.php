<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Selection;

/**
 * What the article says, article-scoped (ADR-019 §3).
 *
 * It knows nothing about beats, cameras, shots or vendors — it is true before
 * anyone decides to make a film of it. Phase 1 builds it from a human-authored
 * scenario; Phase 2 builds it from an extractor. Selection cannot tell which,
 * and that indistinguishability is the test of this boundary.
 *
 * `topicEntity` is identity — "the article is about Moonrise" — and is true in
 * every shot. Which shot shows what is Selection's problem, not this one's:
 * identity is not staging.
 *
 * It is NOT called a subject or a focus. `focus` is already taken and is
 * shot-scoped (`ShotTruth::$focusEntity`, `camera.focus_node`), and the two
 * legitimately disagree: an article whose topic is the quarterback ends on a shot
 * that holds the football. One word at two scopes is the ambiguity §3 of ADR-019
 * exists to kill.
 */
final class ArticleModel
{
    /**
     * @param array<string, Entity> $entities keyed by id
     * @param ArticleFact[] $facts
     */
    public function __construct(
        public readonly string $id,
        public readonly array $entities,
        public readonly string $topicEntity,
        public readonly array $facts,
        public readonly string $goal = '',
        public readonly string $visualStyle = '',
    ) {}

    /** Every fact a camera could carry, before any shot narrows it. Coverage's denominator. */
    public function selectableFacts(): array
    {
        return array_values(array_filter($this->facts, static fn (ArticleFact $f): bool => $f->isSelectable()));
    }

    public function entity(string $id): ?Entity
    {
        return $this->entities[$id] ?? null;
    }
}
