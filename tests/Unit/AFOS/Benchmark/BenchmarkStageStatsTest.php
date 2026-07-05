<?php

namespace Tests\Unit\AFOS\Benchmark;

use App\Services\AI\AFOS\Benchmark\BenchmarkStageStats;
use App\Services\AI\AFOS\Passes\Pipeline\StageProfile;
use PHPUnit\Framework\TestCase;

class BenchmarkStageStatsTest extends TestCase
{
    // ── recordAll() + describeAll() ───────────────────────────────────────────

    public function test_empty_stats_returns_empty_array(): void
    {
        $stats = new BenchmarkStageStats();
        $this->assertSame([], $stats->describeAll());
    }

    public function test_single_measurement_all_percentiles_equal(): void
    {
        $stats = new BenchmarkStageStats();
        $stats->recordAll([$this->profile('Tier1Stage', 10.0)]);

        $d = $stats->describe('Tier1Stage');

        $this->assertEqualsWithDelta(10.0, $d['avg'], 0.001);
        $this->assertEqualsWithDelta(10.0, $d['min'], 0.001);
        $this->assertEqualsWithDelta(10.0, $d['max'], 0.001);
        $this->assertEqualsWithDelta(10.0, $d['p50'], 0.001);
        $this->assertEqualsWithDelta(10.0, $d['p90'], 0.001);
        $this->assertEqualsWithDelta(10.0, $d['p95'], 0.001);
        $this->assertEqualsWithDelta(10.0, $d['p99'], 0.001);
    }

    public function test_avg_min_max_are_correct(): void
    {
        $stats = new BenchmarkStageStats();

        foreach ([1.0, 2.0, 3.0, 4.0, 5.0] as $ms) {
            $stats->recordAll([$this->profile('ShotValidationStage', $ms)]);
        }

        $d = $stats->describe('ShotValidationStage');
        $this->assertEqualsWithDelta(3.0, $d['avg'], 0.001);
        $this->assertEqualsWithDelta(1.0, $d['min'], 0.001);
        $this->assertEqualsWithDelta(5.0, $d['max'], 0.001);
    }

    public function test_p50_median_odd_count(): void
    {
        // sorted: [1, 2, 3, 4, 5] — median = 3
        $stats = new BenchmarkStageStats();
        foreach ([3.0, 1.0, 5.0, 2.0, 4.0] as $ms) {
            $stats->recordAll([$this->profile('Tier2Stage', $ms)]);
        }

        $d = $stats->describe('Tier2Stage');
        $this->assertEqualsWithDelta(3.0, $d['p50'], 0.001);
    }

    public function test_p50_median_even_count(): void
    {
        // sorted: [1, 2, 3, 4] — nearest-rank P50 at ceil(0.5 * 4) = 2 → value 2
        $stats = new BenchmarkStageStats();
        foreach ([4.0, 1.0, 3.0, 2.0] as $ms) {
            $stats->recordAll([$this->profile('Tier1Stage', $ms)]);
        }

        $d = $stats->describe('Tier1Stage');
        // nearest-rank: ceil(0.5 * 4) = 2 → sorted[1] = 2.0
        $this->assertEqualsWithDelta(2.0, $d['p50'], 0.001);
    }

    public function test_p99_detects_spike(): void
    {
        // 9 normal + 1 spike = 10 total
        // nearest-rank P99: ceil(0.99 * 10) - 1 = 10 - 1 = 9 → sorted[9] = 999.0
        $stats = new BenchmarkStageStats();
        for ($i = 0; $i < 9; $i++) {
            $stats->recordAll([$this->profile('BackendStage', 5.0)]);
        }
        $stats->recordAll([$this->profile('BackendStage', 999.0)]);  // spike

        $d = $stats->describe('BackendStage');
        $this->assertGreaterThan(100.0, $d['p99'], 'P99 should detect the spike');
        $this->assertLessThan(10.0, $d['p50'], 'P50 should be unaffected by spike');
    }

    public function test_p90_separates_bulk_from_tail(): void
    {
        $stats = new BenchmarkStageStats();

        // 9 measurements at 10ms + 1 at 100ms
        for ($i = 0; $i < 9; $i++) {
            $stats->recordAll([$this->profile('Tier3Stage', 10.0)]);
        }
        $stats->recordAll([$this->profile('Tier3Stage', 100.0)]);

        $d = $stats->describe('Tier3Stage');
        // sorted: [10,10,10,10,10,10,10,10,10,100], nearest-rank P90: ceil(0.9*10)=9 → 10.0
        $this->assertEqualsWithDelta(10.0, $d['p90'], 0.001);
        $this->assertEqualsWithDelta(100.0, $d['max'], 0.001);
    }

    // ── Multiple stages tracked independently ─────────────────────────────────

    public function test_multiple_stages_tracked_independently(): void
    {
        $stats = new BenchmarkStageStats();

        for ($i = 0; $i < 5; $i++) {
            $stats->recordAll([
                $this->profile('ShotValidationStage', 1.0),
                $this->profile('Tier1Stage', 50.0),
            ]);
        }

        $shot = $stats->describe('ShotValidationStage');
        $t1   = $stats->describe('Tier1Stage');

        $this->assertEqualsWithDelta(1.0, $shot['avg'], 0.001);
        $this->assertEqualsWithDelta(50.0, $t1['avg'], 0.001);
    }

    public function test_describe_all_returns_all_stage_names(): void
    {
        $stats = new BenchmarkStageStats();

        $stats->recordAll([
            $this->profile('ShotValidationStage', 1.0),
            $this->profile('Tier1Stage', 2.0),
            $this->profile('BackendStage', 3.0),
        ]);

        $all = $stats->describeAll();

        $this->assertArrayHasKey('ShotValidationStage', $all);
        $this->assertArrayHasKey('Tier1Stage', $all);
        $this->assertArrayHasKey('BackendStage', $all);
    }

    public function test_describe_all_each_entry_has_required_keys(): void
    {
        $stats = new BenchmarkStageStats();
        $stats->recordAll([$this->profile('Tier1Stage', 10.0)]);

        $entry = $stats->describeAll()['Tier1Stage'];

        foreach (['avg', 'min', 'max', 'p50', 'p90', 'p95', 'p99'] as $key) {
            $this->assertArrayHasKey($key, $entry, "Missing key: {$key}");
            $this->assertIsFloat($entry[$key]);
        }
    }

    public function test_describe_unknown_stage_returns_empty_or_null(): void
    {
        $stats = new BenchmarkStageStats();
        $stats->recordAll([$this->profile('Tier1Stage', 10.0)]);

        // describeAll() shouldn't include a stage that was never recorded
        $all = $stats->describeAll();
        $this->assertArrayNotHasKey('NonExistentStage', $all);
    }

    // ── recordAll() processes profiles array ──────────────────────────────────

    public function test_record_all_processes_multiple_profiles_at_once(): void
    {
        $stats = new BenchmarkStageStats();

        // Simulates a single shot's full pipeline profiles
        $stats->recordAll([
            $this->profile('ShotValidationStage', 0.5),
            $this->profile('Tier1Stage', 15.0),
            $this->profile('Tier2Stage', 12.0),
            $this->profile('CameraValidationStage', 0.3),
            $this->profile('Tier3Stage', 8.0),
            $this->profile('BackendStage', 2.0),
        ]);

        $all = $stats->describeAll();
        $this->assertCount(6, $all);
        $this->assertEqualsWithDelta(15.0, $all['Tier1Stage']['avg'], 0.001);
    }

    public function test_accumulated_across_multiple_shots(): void
    {
        $stats = new BenchmarkStageStats();

        $stats->recordAll([$this->profile('Tier1Stage', 10.0)]);
        $stats->recordAll([$this->profile('Tier1Stage', 20.0)]);
        $stats->recordAll([$this->profile('Tier1Stage', 30.0)]);

        $d = $stats->describe('Tier1Stage');
        $this->assertEqualsWithDelta(20.0, $d['avg'], 0.001);
        $this->assertEqualsWithDelta(10.0, $d['min'], 0.001);
        $this->assertEqualsWithDelta(30.0, $d['max'], 0.001);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function profile(string $name, float $ms): StageProfile
    {
        return new StageProfile(stageName: $name, durationMs: $ms, succeeded: true);
    }
}
