<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Character;

/**
 * Semantic emotional state of a character.
 *
 * Values map to prompt language — PromptCompiler combines with EmotionIntensity:
 *   FEAR + SUBTLE  → "subtly worried expression"
 *   FEAR + INTENSE → "terrified expression"
 */
enum EmotionalState: string
{
    case NEUTRAL       = 'neutral';
    case JOY           = 'joy';
    case FEAR          = 'fear';
    case ANGER         = 'anger';
    case SADNESS       = 'sadness';
    case DETERMINATION = 'determination';
    case SURPRISE      = 'surprise';
}
