<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning\Estimators;

final class CostEstimator
{
    private const COST_PER_SHOT_USD = 0.12;

    public function estimate(int $shotCount): float
    {
        return $shotCount * self::COST_PER_SHOT_USD;
    }
}
