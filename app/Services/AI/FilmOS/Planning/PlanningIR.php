<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning;

final class PlanningIR
{
    public function __construct(
        public readonly string $traceId,
        public readonly int    $version,
        public readonly string $shotId,
        public readonly int    $shotOrder,
        public readonly string $goalId,
        public readonly array  $renderHints = [],
        public readonly array  $constraints = [],
        public readonly array  $attributes  = [],
    ) {}
}
