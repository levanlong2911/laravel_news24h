<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Benchmark;

use App\Services\AI\FilmOS\Benchmark\BenchmarkRecorder;
use App\Services\AI\FilmOS\Planning\PlanningIR;
use App\Services\AI\FilmOS\Runtime\ProviderResult;
use App\Services\AI\FilmOS\Runtime\RuntimeEvent;
use PHPUnit\Framework\TestCase;

final class BenchmarkRecorderTest extends TestCase
{
    private const TRACE_ID    = 'trace-bench-001';
    private const PROVIDER    = 'kling';
    private const REQUEST_ID  = 'req-abc123';
    private const ASSET_URL   = 'https://cdn.example.com/video.mp4';

    // ── Happy path ────────────────────────────────────────────────────────────

    /** @test */
    public function record_maps_trace_id_and_provider_from_provider_result(): void
    {
        $result = $this->recorder()->record(
            $this->completedResult(),
            $this->planningIr(),
        );

        $this->assertSame(self::TRACE_ID, $result->traceId);
        $this->assertSame(self::PROVIDER, $result->provider);
    }

    /** @test */
    public function record_maps_goal_id_from_planning_ir(): void
    {
        $result = $this->recorder()->record(
            $this->completedResult(),
            $this->planningIr(goalId: 'shot_hook'),
        );

        $this->assertSame('shot_hook', $result->goalId);
    }

    /** @test */
    public function record_uses_explicit_planner_name(): void
    {
        $result = $this->recorder()->record(
            $this->completedResult(),
            $this->planningIr(),
            plannerName: 'sports-planner-v1',
        );

        $this->assertSame('sports-planner-v1', $result->plannerName);
    }

    /** @test */
    public function record_defaults_planner_name_to_default(): void
    {
        $result = $this->recorder()->record(
            $this->completedResult(),
            $this->planningIr(),
        );

        $this->assertSame('default', $result->plannerName);
    }

    /** @test */
    public function record_maps_cost_and_latency_from_provider_result(): void
    {
        $result = $this->recorder()->record(
            $this->completedResult(cost: 0.12, latency: 18.5),
            $this->planningIr(),
        );

        $this->assertSame(0.12, $result->cost);
        $this->assertSame(18.5, $result->latencySeconds);
    }

    /** @test */
    public function record_stores_request_id_asset_url_duration_in_attributes(): void
    {
        $result = $this->recorder()->record(
            $this->completedResult(assetUrl: self::ASSET_URL, duration: 5.0),
            $this->planningIr(),
        );

        $this->assertSame(self::REQUEST_ID, $result->attributes['requestId']);
        $this->assertSame(self::ASSET_URL, $result->attributes['assetUrl']);
        $this->assertSame(5.0, $result->attributes['duration']);
    }

    /** @test */
    public function record_leaves_annotation_fields_at_zero_pending_c6(): void
    {
        $result = $this->recorder()->record(
            $this->completedResult(),
            $this->planningIr(),
        );

        $this->assertSame(0.0, $result->qualityScore);
        $this->assertSame(0.0, $result->roi);
        $this->assertSame(0.0, $result->score);
    }

    // ── Guard ─────────────────────────────────────────────────────────────────

    /** @test */
    public function record_throws_when_result_is_not_download_completed(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('DOWNLOAD_COMPLETED');

        $this->recorder()->record(
            new ProviderResult(
                traceId:   self::TRACE_ID,
                provider:  self::PROVIDER,
                requestId: self::REQUEST_ID,
                status:    RuntimeEvent::FAILED,
            ),
            $this->planningIr(),
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function recorder(): BenchmarkRecorder
    {
        return new BenchmarkRecorder();
    }

    private function completedResult(
        string $assetUrl = self::ASSET_URL,
        float  $duration = 5.0,
        float  $cost     = 0.0,
        float  $latency  = 0.0,
    ): ProviderResult {
        return new ProviderResult(
            traceId:   self::TRACE_ID,
            provider:  self::PROVIDER,
            requestId: self::REQUEST_ID,
            status:    RuntimeEvent::DOWNLOAD_COMPLETED,
            assetUrl:  $assetUrl,
            duration:  $duration,
            cost:      $cost,
            latency:   $latency,
        );
    }

    private function planningIr(string $goalId = 'shot_hook'): PlanningIR
    {
        return new PlanningIR(
            traceId:   self::TRACE_ID,
            version:   1,
            shotId:    'hook_1',
            shotOrder: 1,
            goalId:    $goalId,
        );
    }
}
