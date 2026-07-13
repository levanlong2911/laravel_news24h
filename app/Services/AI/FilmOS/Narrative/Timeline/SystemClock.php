<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline;

final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }
}
