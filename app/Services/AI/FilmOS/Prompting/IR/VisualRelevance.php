<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\IR;

/**
 * How much a key visual should dominate the frame — the article's own
 * visual-priority signal (facts[].visual_relevance), carried into the IR so
 * the renderer can rank must-show visuals ahead of nice-to-have ones.
 *
 * Backed by the exact strings the scenario/article uses (HIGH/MEDIUM/LOW).
 */
enum VisualRelevance: string
{
    case HIGH   = 'HIGH';
    case MEDIUM = 'MEDIUM';
    case LOW    = 'LOW';

    /** Higher rank = shown first / more emphatically. */
    public function rank(): int
    {
        return match ($this) {
            self::HIGH   => 3,
            self::MEDIUM => 2,
            self::LOW    => 1,
        };
    }
}
