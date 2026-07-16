<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Selection;

/** How much a fact is worth to a camera. Authored per fact in the article model. */
enum FactRelevance: string
{
    case HIGH   = 'HIGH';
    case MEDIUM = 'MEDIUM';
    case LOW    = 'LOW';

    public function rank(): int
    {
        return match ($this) {
            self::HIGH   => 3,
            self::MEDIUM => 2,
            self::LOW    => 1,
        };
    }
}
