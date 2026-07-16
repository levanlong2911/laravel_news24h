<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Selection;

/**
 * Who is on screen for one beat — the ONLY thing Selection learns about staging.
 *
 * This exists so Selection never reads a benchmark schema. A scenario builds one
 * of these from its `scene_nodes`; production will build one from its planner.
 * Selection cannot tell them apart, so the day the benchmark goes away the policy
 * does not change. Depending on `scene_nodes` directly would have welded the
 * policy to a file format that production does not have.
 *
 * It speaks in ENTITY ids, never node ids: nodes are the scenario's vocabulary.
 *
 * It carries staging and NOTHING else. The article's topic is article-scoped and
 * lives in ArticleModel — copying it into every beat would denormalize a
 * single-source fact into N shot-scoped objects, which is exactly the reason
 * ADR-019 §3.1 refuses to enrich `ShotDTO` with article facts. The policy holds
 * the model already; it can ask.
 *
 * Staging is an input here, not a prediction. Selection decides what we SAY about
 * whoever is present (ADR-019 §6.1: Article Model -> Shot Truth), and production
 * already supplies staging of its own (`ShotDTO.subActor` / `subObj`).
 */
final class BeatContext
{
    /** @param string[] $visibleEntities entity ids */
    public function __construct(
        public readonly string $beat,
        public readonly array $visibleEntities,
    ) {}

    public function sees(string $entityId): bool
    {
        return in_array($entityId, $this->visibleEntities, true);
    }
}
