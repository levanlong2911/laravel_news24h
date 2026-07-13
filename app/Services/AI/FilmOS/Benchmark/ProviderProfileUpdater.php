<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Benchmark;

use App\Services\AI\FilmOS\Render\ProviderProfile;

/**
 * Produces an updated ProviderProfile from aggregated benchmark metrics.
 *
 * All scores are normalized to 0.0–1.0 where higher is always better:
 *   costEfficiency = 1 - clamp(avgCost / maxExpectedCost)
 *   latencyScore   = 1 - clamp(avgLatency / maxExpectedLatency)
 *   quality        = avgQuality (already 0.0–1.0)
 *   reliability    = exponential saturation of sampleSize (0 → 0.0, ∞ → 0.95)
 *
 * Constructor parameters override defaults; wire from config('filmos.learning.*')
 * in production. Tests pass values directly to stay independent of Laravel config.
 *
 * ProviderProfile is NOT persisted — it is recomputed from BenchmarkRepository data.
 * Benchmark data is the source of truth; ProviderProfile is a derived view.
 */
final class ProviderProfileUpdater
{
    public function __construct(
        private readonly float $maxExpectedCost    = 0.50,  // USD / video
        private readonly float $maxExpectedLatency = 300.0, // seconds
    ) {}

    public function update(ProviderProfile $profile, ProviderMetrics $metrics): ProviderProfile
    {
        if (!$metrics->hasData()) {
            return $profile;
        }

        return new ProviderProfile(
            provider:    $profile->provider,
            cost:        $this->costEfficiency($metrics->avgCost),
            quality:     $metrics->avgQuality,
            latency:     $this->latencyScore($metrics->avgLatency),
            reliability: $this->reliability($metrics->sampleSize),
        );
    }

    private function costEfficiency(float $avgCost): float
    {
        return max(0.0, 1.0 - min(1.0, $avgCost / $this->maxExpectedCost));
    }

    private function latencyScore(float $avgLatency): float
    {
        return max(0.0, 1.0 - min(1.0, $avgLatency / $this->maxExpectedLatency));
    }

    /**
     * Exponential saturation: low samples → low confidence; grows to max 0.95.
     *
     * Approximate values (decay constant k=10):
     *   n=0  → 0.00 (no data — trust nothing)
     *   n=1  → 0.37
     *   n=3  → 0.48
     *   n=10 → 0.74
     *   n=30 → 0.95 (saturates)
     *   n=∞  → 0.95 (hard cap)
     */
    private function reliability(int $sampleSize): float
    {
        if ($sampleSize === 0) {
            return 0.0;
        }
        return min(0.95, 0.30 + 0.70 * (1.0 - exp(-$sampleSize / 10.0)));
    }
}
