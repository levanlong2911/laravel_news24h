<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Benchmark;

use App\Services\AI\FilmOS\Benchmark\BenchmarkAggregator;
use App\Services\AI\FilmOS\Benchmark\BenchmarkResult;
use PHPUnit\Framework\TestCase;

final class BenchmarkAggregatorTest extends TestCase
{
    private const PROVIDER = 'kling';

    // ── Empty input ───────────────────────────────────────────────────────────

    /** @test */
    public function empty_results_return_zero_metrics_with_sample_size_zero(): void
    {
        $metrics = $this->aggregator()->aggregate(self::PROVIDER, []);

        $this->assertSame(self::PROVIDER, $metrics->provider);
        $this->assertSame(0, $metrics->sampleSize);
        $this->assertSame(0.0, $metrics->avgCost);
        $this->assertSame(0.0, $metrics->avgLatency);
        $this->assertSame(0.0, $metrics->avgQuality);
        $this->assertFalse($metrics->hasData());
    }

    // ── Single record ─────────────────────────────────────────────────────────

    /** @test */
    public function single_result_averages_equal_that_record(): void
    {
        $result = $this->benchmarkResult(cost: 0.10, latency: 60.0, quality: 0.80);

        $metrics = $this->aggregator()->aggregate(self::PROVIDER, [$result]);

        $this->assertSame(1, $metrics->sampleSize);
        $this->assertEqualsWithDelta(0.10, $metrics->avgCost, 0.0001);
        $this->assertEqualsWithDelta(60.0, $metrics->avgLatency, 0.0001);
        $this->assertEqualsWithDelta(0.80, $metrics->avgQuality, 0.0001);
        $this->assertTrue($metrics->hasData());
    }

    // ── Multiple records ──────────────────────────────────────────────────────

    /** @test */
    public function multiple_results_produce_correct_averages(): void
    {
        $results = [
            $this->benchmarkResult(cost: 0.10, latency: 100.0, quality: 0.60),
            $this->benchmarkResult(cost: 0.20, latency: 200.0, quality: 0.80),
            $this->benchmarkResult(cost: 0.30, latency: 300.0, quality: 1.00),
        ];

        $metrics = $this->aggregator()->aggregate(self::PROVIDER, $results);

        $this->assertSame(3, $metrics->sampleSize);
        $this->assertEqualsWithDelta(0.20, $metrics->avgCost, 0.0001);
        $this->assertEqualsWithDelta(200.0, $metrics->avgLatency, 0.0001);
        $this->assertEqualsWithDelta(0.80, $metrics->avgQuality, 0.0001);
    }

    /** @test */
    public function provider_name_is_preserved_in_output(): void
    {
        $metrics = $this->aggregator()->aggregate('runway', [
            $this->benchmarkResult(),
        ]);

        $this->assertSame('runway', $metrics->provider);
    }

    /** @test */
    public function accepts_generator_as_iterable(): void
    {
        $generator = (function () {
            yield $this->benchmarkResult(cost: 0.10, latency: 50.0, quality: 0.70);
            yield $this->benchmarkResult(cost: 0.30, latency: 150.0, quality: 0.90);
        })();

        $metrics = $this->aggregator()->aggregate(self::PROVIDER, $generator);

        $this->assertSame(2, $metrics->sampleSize);
        $this->assertEqualsWithDelta(0.20, $metrics->avgCost, 0.0001);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function aggregator(): BenchmarkAggregator
    {
        return new BenchmarkAggregator();
    }

    private function benchmarkResult(
        float $cost    = 0.0,
        float $latency = 0.0,
        float $quality = 0.0,
    ): BenchmarkResult {
        return new BenchmarkResult(
            traceId:        'trace-' . uniqid(),
            provider:       self::PROVIDER,
            plannerName:    'default',
            goalId:         'shot_hook',
            score:          0.0,
            roi:            0.0,
            cost:           $cost,
            latencySeconds: $latency,
            qualityScore:   $quality,
        );
    }
}
