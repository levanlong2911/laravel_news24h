<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning;

final class PlannedShot
{
    public function __construct(
        public readonly int    $position,
        public readonly string $subGoalId,
        public readonly string $description,
        public readonly array  $execution,  // ['visualStrategy' => VisualStrategy, 'camera' => [...]]
        public readonly string $rationale,
    ) {}
}
