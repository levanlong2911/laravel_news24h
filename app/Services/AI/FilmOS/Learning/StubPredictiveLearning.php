<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Learning;

use App\Services\AI\FilmOS\Planning\ShotSequencePlan;

final class StubPredictiveLearning implements PredictiveLearning
{
    public function predict(ShotSequencePlan $plan, array $contextFeatures): PredictionResult
    {
        return PredictionResult::noPrior('Phase 1 — no calibration data yet');
    }

    public function calibrate(ShotSequencePlan $plan, array $actualOutcomes): void
    {
        // Phase 1 stub — log only; no model update
    }
}
