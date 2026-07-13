<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\World;

final class WorldFact
{
    public function __construct(
        public readonly string $key,        // unique per world, e.g. "time_of_day", "weather"
        public readonly string $value,      // e.g. "night", "rainy"
        public readonly int    $assertedAt, // shotOrdinal when this fact was asserted
    ) {}
}
