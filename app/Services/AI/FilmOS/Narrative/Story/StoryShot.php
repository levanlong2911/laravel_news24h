<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Story;

/**
 * One planned shot in the story projection.
 *
 * INVARIANT: immutable. The projection never mutates a StoryShot — any change
 * creates a new instance (same rule as WorldObject/CharacterProfile/NarrativeFinding).
 *
 * $beat is nullable: shots born from the generic fallback GoalGraph (no
 * narrative structure available) carry no beat. PromptCompiler treats
 * null as "no beat-specific prompt language".
 */
final class StoryShot
{
    public function __construct(
        public readonly string     $shotId,
        public readonly int        $ordinal,
        public readonly string     $goalType,
        public readonly string     $description,
        public readonly ?StoryBeat $beat = null,
    ) {}
}
