<?php

declare(strict_types=1);

namespace App\Video\Concept\Support;

use DateTimeImmutable;
use DateTimeZone;

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            'now',
            new DateTimeZone('UTC')
        );
    }
}
