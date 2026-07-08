<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Learning;

use App\Services\AI\FilmOS\Planning\ShotSequencePlan;

interface PredictiveLearning
{
    public function predict(ShotSequencePlan $plan, array $contextFeatures): PredictionResult;

    public function calibrate(ShotSequencePlan $plan, array $actualOutcomes): void;
}
