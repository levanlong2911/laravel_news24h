<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline;

interface Clock
{
    public function now(): int;  // unix timestamp
}
