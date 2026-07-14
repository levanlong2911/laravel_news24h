<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Story;

/**
 * Read-only view of story state at a projection point.
 *
 * PromptCompiler, D5 QA and C.8 benchmark depend on this interface,
 * not StoryProjection — same rule as WorldView/SceneView/CharacterView.
 *
 * INVARIANT: this is ordered KNOWLEDGE, not an ordered collection.
 * The ordinal is the shot's IDENTITY, not an array position — ordinal gaps
 * (e.g. shots 1, 4, 9) are legal states after inserts or planner regeneration.
 * Consumers must never assume ordinals are contiguous or zero-based.
 */
interface StoryView
{
    public function hasShot(int $ordinal): bool;

    public function shotAt(int $ordinal): ?StoryShot;

    /** The most recent planned shot (highest ordinal), or null when the story is empty. */
    public function latestShot(): ?StoryShot;

    /** Beat of the shot at $ordinal — null if shot missing or shot has no beat.
     *  Convenience delegate over shotAt() — no separate beat map is stored. */
    public function beatOf(int $ordinal): ?StoryBeat;

    /** @return array<int, StoryShot> keyed by shot ordinal */
    public function allShots(): array;
}
