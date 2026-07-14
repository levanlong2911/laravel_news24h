<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Story;

/**
 * Narrative outcome contract — how a shot ENDS as story, not as camera.
 *
 * "Ball disappears into the night sky", "Door slowly closes", "Fade to black"
 * are all endings; camera merely expresses them. This lives in the Story
 * domain so D4 (Scene/Camera) never has to know story-outcome concepts.
 *
 * CONTRACT NOTE (frozen 2026-07-13): $description is the TEMPORARY v1
 * representation. Future evolution may add typed outcome metadata
 * (e.g. outcome kind: SUBJECT_EXITS / GOAL_ACHIEVED / FADE_TO_BLACK…).
 * Do NOT treat free-text as the permanent contract — extend this VO
 * additively when typed outcomes earn a real use case.
 *
 * Immutable — same rule as StoryShot/WorldObject/NarrativeFinding.
 */
final class EndingFrame
{
    public function __construct(
        public readonly string $description,
    ) {}
}
