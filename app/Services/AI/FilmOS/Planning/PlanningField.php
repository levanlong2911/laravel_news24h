<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning;

final class PlanningField
{
    public function __construct(
        public readonly string $key,
        public readonly mixed  $value,
        public readonly int    $priority   = 50,
        public readonly float  $confidence = 1.0,
        public readonly string $source     = '',
    ) {}
}
