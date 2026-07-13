<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Benchmark;

use App\Services\AI\FilmOS\Benchmark\ProviderMetrics;
use App\Services\AI\FilmOS\Benchmark\ProviderProfileUpdater;
use App\Services\AI\FilmOS\Render\ProviderProfile;
use PHPUnit\Framework\TestCase;

final class ProviderProfileUpdaterTest extends TestCase
{
    private const PROVIDER = 'kling';

    // ── costEfficiency ────────────────────────────────────────────────────────

    /** @test */
    public function low_cost_produces_high_efficiency(): void
    {
        // $0.05 / $0.50 max = 0.10 → efficiency = 0.90
        $profile = $this->update(avgCost: 0.05);
        $this->assertEqualsWithDelta(0.90, $profile->cost, 0.0001);
    }

    /** @test */
    public function mid_cost_produces_mid_efficiency(): void
    {
        // $0.25 / $0.50 = 0.50 → efficiency = 0.50
        $profile = $this->update(avgCost: 0.25);
        $this->assertEqualsWithDelta(0.50, $profile->cost, 0.0001);
    }

    /** @test */
    public function max_cost_produces_zero_efficiency(): void
    {
        // $0.50 / $0.50 = 1.0 → efficiency = 0.00
        $profile = $this->update(avgCost: 0.50);
        $this->assertEqualsWithDelta(0.00, $profile->cost, 0.0001);
    }

    /** @test */
    public function cost_above_max_is_clamped_to_zero(): void
    {
        $profile = $this->update(avgCost: 0.80);
        $this->assertSame(0.0, $profile->cost);
    }

    /** @test */
    public function custom_max_cost_scales_score_correctly(): void
    {
        // maxCost=$1.00, avgCost=$0.25 → 1 - (0.25/1.00) = 0.75
        $updater = new ProviderProfileUpdater(maxExpectedCost: 1.00);
        $profile = $updater->update(
            new ProviderProfile(provider: self::PROVIDER),
            $this->metrics(avgCost: 0.25, sampleSize: 3),
        );
        $this->assertEqualsWithDelta(0.75, $profile->cost, 0.0001);
    }

    // ── latencyScore ──────────────────────────────────────────────────────────

    /** @test */
    public function zero_latency_produces_perfect_score(): void
    {
        $profile = $this->update(avgLatency: 0.0);
        $this->assertEqualsWithDelta(1.00, $profile->latency, 0.0001);
    }

    /** @test */
    public function half_max_latency_produces_half_score(): void
    {
        // 150s / 300s max = 0.50 → score = 0.50
        $profile = $this->update(avgLatency: 150.0);
        $this->assertEqualsWithDelta(0.50, $profile->latency, 0.0001);
    }

    /** @test */
    public function max_latency_produces_zero_score(): void
    {
        $profile = $this->update(avgLatency: 300.0);
        $this->assertEqualsWithDelta(0.00, $profile->latency, 0.0001);
    }

    /** @test */
    public function latency_above_max_is_clamped_to_zero(): void
    {
        $profile = $this->update(avgLatency: 500.0);
        $this->assertSame(0.0, $profile->latency);
    }

    /** @test */
    public function custom_max_latency_scales_score_correctly(): void
    {
        // maxLatency=60s, avgLatency=30s → 1 - (30/60) = 0.50
        $updater = new ProviderProfileUpdater(maxExpectedLatency: 60.0);
        $profile = $updater->update(
            new ProviderProfile(provider: self::PROVIDER),
            $this->metrics(avgLatency: 30.0, sampleSize: 3),
        );
        $this->assertEqualsWithDelta(0.50, $profile->latency, 0.0001);
    }

    // ── quality ───────────────────────────────────────────────────────────────

    /** @test */
    public function quality_maps_directly_from_avg_quality(): void
    {
        $profile = $this->update(avgQuality: 0.75);
        $this->assertEqualsWithDelta(0.75, $profile->quality, 0.0001);
    }

    // ── reliability (graduated exponential) ──────────────────────────────────

    /** @test */
    public function zero_samples_produces_zero_reliability(): void
    {
        // No data: trust nothing
        $profile = $this->update(sampleSize: 0);
        // update() returns input profile unchanged when hasData() is false
        $this->assertEqualsWithDelta(0.5, $profile->reliability, 0.0001);
    }

    /** @test */
    public function one_sample_produces_low_reliability(): void
    {
        // n=1 → ~0.37 (low confidence, below 0.50 default)
        $profile = $this->update(sampleSize: 1);
        $this->assertGreaterThan(0.30, $profile->reliability);
        $this->assertLessThan(0.50, $profile->reliability);
    }

    /** @test */
    public function three_samples_produces_building_reliability(): void
    {
        // n=3 → ~0.48
        $profile = $this->update(sampleSize: 3);
        $this->assertGreaterThan(0.40, $profile->reliability);
        $this->assertLessThan(0.60, $profile->reliability);
    }

    /** @test */
    public function ten_samples_produces_good_reliability(): void
    {
        // n=10 → ~0.74
        $profile = $this->update(sampleSize: 10);
        $this->assertGreaterThan(0.65, $profile->reliability);
        $this->assertLessThan(0.85, $profile->reliability);
    }

    /** @test */
    public function reliability_is_monotonically_increasing_before_saturation(): void
    {
        // Checks growth in the range where the curve has not yet saturated (k=10 saturates ~n=20).
        $prev = 0.0;
        foreach ([1, 2, 5, 10, 15] as $n) {
            $curr = $this->update(sampleSize: $n)->reliability;
            $this->assertGreaterThan($prev, $curr, "Reliability should grow at n={$n}");
            $prev = $curr;
        }
    }

    /** @test */
    public function reliability_saturates_at_zero_point_nine_five(): void
    {
        // Large sample → hard cap at 0.95
        $profile = $this->update(sampleSize: 1000);
        $this->assertEqualsWithDelta(0.95, $profile->reliability, 0.001);
    }

    // ── Provider identity ─────────────────────────────────────────────────────

    /** @test */
    public function provider_name_is_preserved_from_input_profile(): void
    {
        $profile = $this->update(provider: 'runway');
        $this->assertSame('runway', $profile->provider);
    }

    // ── No-data guard ─────────────────────────────────────────────────────────

    /** @test */
    public function empty_metrics_returns_input_profile_unchanged(): void
    {
        $original = new ProviderProfile(
            provider: self::PROVIDER,
            cost:     0.42,
            quality:  0.88,
        );
        $emptyMetrics = new ProviderMetrics(
            provider:   self::PROVIDER,
            avgCost:    0.0,
            avgLatency: 0.0,
            avgQuality: 0.0,
            sampleSize: 0,
        );

        $result = (new ProviderProfileUpdater())->update($original, $emptyMetrics);

        $this->assertSame($original, $result);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function update(
        string $provider    = self::PROVIDER,
        float  $avgCost     = 0.0,
        float  $avgLatency  = 0.0,
        float  $avgQuality  = 0.0,
        int    $sampleSize  = 3,
    ): ProviderProfile {
        return (new ProviderProfileUpdater())->update(
            new ProviderProfile(provider: $provider),
            $this->metrics(
                provider:   $provider,
                avgCost:    $avgCost,
                avgLatency: $avgLatency,
                avgQuality: $avgQuality,
                sampleSize: $sampleSize,
            ),
        );
    }

    private function metrics(
        string $provider    = self::PROVIDER,
        float  $avgCost     = 0.0,
        float  $avgLatency  = 0.0,
        float  $avgQuality  = 0.0,
        int    $sampleSize  = 3,
    ): ProviderMetrics {
        return new ProviderMetrics(
            provider:   $provider,
            avgCost:    $avgCost,
            avgLatency: $avgLatency,
            avgQuality: $avgQuality,
            sampleSize: $sampleSize,
        );
    }
}
