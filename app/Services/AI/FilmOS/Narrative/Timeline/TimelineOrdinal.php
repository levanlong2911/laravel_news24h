<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\Timeline;

final class TimelineOrdinal
{
    /** World/character baseline events — appended before shot 0 so replay(0) includes them. */
    public const BASELINE = -1;

    private function __construct() {}
}
