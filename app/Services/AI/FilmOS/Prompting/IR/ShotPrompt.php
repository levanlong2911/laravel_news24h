<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\IR;

use App\Services\AI\FilmOS\Narrative\Character\CharacterEmotion;
use App\Services\AI\FilmOS\Narrative\Scene\CameraConfiguration;
use App\Services\AI\FilmOS\Narrative\Story\EndingFrame;
use App\Services\AI\FilmOS\Narrative\Story\StoryBeat;

/**
 * Prompt IR for one shot — ORGANIZED knowledge, never rendered language.
 *
 * Typed contract values (StoryBeat, CameraConfiguration, CharacterEmotion,
 * EndingFrame) stay typed so each vendor adapter translates them its own way:
 * Kling says "85mm telephoto compression", Veo says it differently — FilmOS
 * is never locked to one vendor's prompt syntax.
 *
 * World knowledge is the exception: it arrives pre-flattened as
 * PromptEnvironment so adapters never touch the World domain.
 *
 * Immutable.
 */
final class ShotPrompt
{
    /**
     * @param array<string, CharacterEmotion> $emotions characterId => emotion known at this ordinal
     */
    public function __construct(
        public readonly int                  $ordinal,
        public readonly ?StoryBeat           $beat,
        public readonly string               $action,       // StoryShot description — what happens
        public readonly array                $emotions,
        public readonly ?CameraConfiguration $camera,
        public readonly PromptEnvironment    $environment,
        public readonly ?EndingFrame         $endingFrame = null,
    ) {}

    /** Convenience for adapters — avoids repeated count() checks; NOT an emotion selector. */
    public function hasEmotions(): bool
    {
        return $this->emotions !== [];
    }
}
