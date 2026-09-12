<?php

declare(strict_types=1);

namespace App\Video\Concept\Support;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
