<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Character;

/**
 * How strongly an emotion is expressed.
 *
 * Carries as much prompt weight as the emotion itself:
 * "slightly worried" and "terrified" produce completely different renders.
 * QA also uses intensity to validate emotional progression across shots
 * (FEAR/MODERATE → FEAR/INTENSE is a valid arc; information lost without it).
 */
enum EmotionIntensity: string
{
    case SUBTLE   = 'subtle';
    case MODERATE = 'moderate';
    case INTENSE  = 'intense';
}
