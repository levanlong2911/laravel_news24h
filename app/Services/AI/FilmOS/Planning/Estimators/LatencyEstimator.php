<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning\Estimators;

final class LatencyEstimator
{
    private const MS_PER_SHOT = 2000;

    public function estimate(int $shotCount, bool $allowParallel = true): int
    {
        if ($allowParallel) {
            // CRITICAL tasks run in parallel; approximate critical path as 2 shots deep
            return (int) ceil($shotCount / 2) * self::MS_PER_SHOT;
        }
        return $shotCount * self::MS_PER_SHOT;
    }
}
