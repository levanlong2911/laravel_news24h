<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Analysis;

use App\Services\AI\FilmOS\Analysis\OutcomeJoiner;
use App\Services\AI\FilmOS\Analysis\ShotKnowledge;
use App\Services\AI\FilmOS\Benchmark\BenchmarkResult;
use App\Services\AI\FilmOS\Narrative\Story\StoryBeat;
use PHPUnit\Framework\TestCase;

final class OutcomeJoinerTest extends TestCase
{
    // ── Join key: ordinal (shot identity), never goalId ───────────────────────

    public function test_joins_results_to_knowledge_by_ordinal(): void
    {
        $knowledge = [0 => $this->knowledge(0, 'shot_hook', StoryBeat::HOOK)];
        $results   = [$this->benchmarkResult(ordinal: 0, quality: 8.0, latency: 30.0, cost: 0.5)];

        $outcomes = (new OutcomeJoiner())->join($knowledge, $results);

        $this->assertCount(1, $outcomes);
        $this->assertSame(0, $outcomes[0]->knowledge->ordinal);
        // Flattened metrics — never the BenchmarkResult object
        $this->assertSame(8.0,  $outcomes[0]->quality);
        $this->assertSame(30.0, $outcomes[0]->latencySeconds);
        $this->assertSame(0.5,  $outcomes[0]->cost);
    }

    public function test_goal_id_is_not_the_join_key(): void
    {
        // goalId differs from shotId (renamed identifier) — join still succeeds via ordinal
        $knowledge = [3 => $this->knowledge(3, 'shot_hook', StoryBeat::HOOK)];
        $results   = [$this->benchmarkResult(ordinal: 3, quality: 7.0, goalId: 'opening_hook_renamed')];

        $outcomes = (new OutcomeJoiner())->join($knowledge, $results);

        $this->assertCount(1, $outcomes);
        $this->assertSame('shot_hook', $outcomes[0]->knowledge->shotId);
    }

    public function test_result_without_ordinal_is_skipped(): void
    {
        // Pre-C.8A legacy rows carry no ordinal — unjoinable, never guessed
        $knowledge = [0 => $this->knowledge(0, 'shot_hook', StoryBeat::HOOK)];
        $results   = [$this->benchmarkResult(ordinal: null, quality: 8.0, goalId: 'shot_hook')];

        $this->assertSame([], (new OutcomeJoiner())->join($knowledge, $results));
    }

    public function test_result_with_unplanned_ordinal_is_skipped(): void
    {
        $knowledge = [0 => $this->knowledge(0, 'shot_hook', StoryBeat::HOOK)];
        $results   = [
            $this->benchmarkResult(ordinal: 0,  quality: 8.0),
            $this->benchmarkResult(ordinal: 99, quality: 2.0),  // never planned here
        ];

        $outcomes = (new OutcomeJoiner())->join($knowledge, $results);

        $this->assertCount(1, $outcomes);
    }

    public function test_empty_inputs_join_to_empty_outcomes(): void
    {
        $this->assertSame([], (new OutcomeJoiner())->join([], []));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function knowledge(int $ordinal, string $shotId, ?StoryBeat $beat = null): ShotKnowledge
    {
        return new ShotKnowledge(
            ordinal: $ordinal,
            shotId:  $shotId,
            beat:    $beat,
            camera:  null,
        );
    }

    private function benchmarkResult(
        ?int   $ordinal,
        float  $quality,
        float  $latency = 30.0,
        float  $cost = 0.5,
        string $goalId = 'shot_x',
    ): BenchmarkResult {
        return new BenchmarkResult(
            traceId:        'trace_' . ($ordinal ?? 'legacy'),
            provider:       'kling',
            plannerName:    'TestPlanner',
            goalId:         $goalId,
            score:          $quality,
            roi:            1.0,
            cost:           $cost,
            latencySeconds: $latency,
            qualityScore:   $quality,
            ordinal:        $ordinal,
        );
    }
}
