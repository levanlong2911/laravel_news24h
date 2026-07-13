<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Character;

/**
 * An emotional state at a point in the story.
 *
 * $cause is an optional extension point: "gunshot", "saw the villa on fire".
 * When present, PromptCompiler can render "frightened after hearing the explosion"
 * instead of just "frightened". Nullable — no caller is required to provide it.
 */
final class CharacterEmotion
{
    public function __construct(
        public readonly EmotionalState   $state,
        public readonly EmotionIntensity $intensity,
        public readonly ?string          $cause = null,
    ) {}
}
