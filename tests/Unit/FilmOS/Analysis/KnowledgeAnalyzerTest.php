<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Analysis;

use App\Services\AI\FilmOS\Analysis\KnowledgeAnalyzer;
use App\Services\AI\FilmOS\Analysis\ShotKnowledge;
use App\Services\AI\FilmOS\Analysis\ShotOutcome;
use App\Services\AI\FilmOS\Narrative\Scene\CameraAngle;
use App\Services\AI\FilmOS\Narrative\Scene\CameraConfiguration;
use App\Services\AI\FilmOS\Narrative\Scene\CameraMovement;
use App\Services\AI\FilmOS\Narrative\Scene\LensType;
use App\Services\AI\FilmOS\Narrative\Scene\ShotType;
use App\Services\AI\FilmOS\Narrative\Story\StoryBeat;
use PHPUnit\Framework\TestCase;

/**
 * Analyzer receives ShotOutcome[] only — no Views, no BenchmarkResult
 * (joining is OutcomeJoiner's job; see OutcomeJoinerTest).
 */
final class KnowledgeAnalyzerTest extends TestCase
{
    // ── Aggregates: averages + sample sizes, precomputed ─────────────────────

    public function test_quality_by_beat_averages_correctly(): void
    {
        $outcomes = [
            $this->outcome($this->knowledge('shot_hook',   0, StoryBeat::HOOK),   quality: 6.0),
            $this->outcome($this->knowledge('shot_hook2',  1, StoryBeat::HOOK),   quality: 8.0),
            $this->outcome($this->knowledge('shot_payoff', 2, StoryBeat::PAYOFF), quality: 9.0),
        ];

        $byBeat = (new KnowledgeAnalyzer())->analyze($outcomes)->qualityByBeat();

        $this->assertSame(7.0, $byBeat['hook']['avgQuality']);
        $this->assertSame(2,   $byBeat['hook']['sampleSize']);
        $this->assertSame(9.0, $byBeat['payoff']['avgQuality']);
        $this->assertSame(1,   $byBeat['payoff']['sampleSize']);
    }

    public function test_quality_by_shot_type_and_lens(): void
    {
        $outcomes = [
            $this->outcome($this->knowledge('a', 0, null, $this->camera(ShotType::CLOSE_UP, LensType::TELEPHOTO)), quality: 9.0),
            $this->outcome($this->knowledge('b', 1, null, $this->camera(ShotType::WIDE,     LensType::WIDE)),      quality: 5.0),
        ];

        $report = (new KnowledgeAnalyzer())->analyze($outcomes);

        $this->assertSame(9.0, $report->qualityByShotType()['close_up']['avgQuality']);
        $this->assertSame(5.0, $report->qualityByShotType()['wide']['avgQuality']);
        $this->assertSame(9.0, $report->qualityByLens()['telephoto']['avgQuality']);
        $this->assertSame(5.0, $report->qualityByLens()['wide']['avgQuality']);
    }

    public function test_shot_without_camera_or_beat_is_excluded_from_those_dimensions(): void
    {
        $outcomes = [$this->outcome($this->knowledge('bare', 0, beat: null, camera: null), quality: 7.0)];

        $report = (new KnowledgeAnalyzer())->analyze($outcomes);

        $this->assertSame([], $report->qualityByBeat());
        $this->assertSame([], $report->qualityByShotType());
        $this->assertSame([], $report->qualityByLens());
        $this->assertSame(1, $report->sampleSize(), 'still counted in outcomes');
    }

    public function test_quality_by_finding_code_correlates_qa_with_outcome(): void
    {
        $outcomes = [
            $this->outcome($this->knowledge('clean', 0, StoryBeat::HOOK), quality: 9.0),
            $this->outcome($this->knowledge('dirty', 1, StoryBeat::HOOK, findingCodes: ['D4.FOCUS_NODE_MISSING']), quality: 4.0),
        ];

        $byCode = (new KnowledgeAnalyzer())->analyze($outcomes)->qualityByFindingCode();

        $this->assertSame(4.0, $byCode['D4.FOCUS_NODE_MISSING']['avgQuality']);
        $this->assertSame(1,   $byCode['D4.FOCUS_NODE_MISSING']['sampleSize']);
        $this->assertArrayNotHasKey('', $byCode);
    }

    // ── Empty input → empty report ────────────────────────────────────────────

    public function test_empty_outcomes_produce_empty_report(): void
    {
        $report = (new KnowledgeAnalyzer())->analyze([]);

        $this->assertSame(0, $report->sampleSize());
        $this->assertSame([], $report->outcomes());
        $this->assertSame([], $report->qualityByBeat());
        $this->assertSame([], $report->qualityByShotType());
        $this->assertSame([], $report->qualityByLens());
        $this->assertSame([], $report->qualityByFindingCode());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function outcome(ShotKnowledge $knowledge, float $quality): ShotOutcome
    {
        return new ShotOutcome(
            knowledge:      $knowledge,
            quality:        $quality,
            latencySeconds: 30.0,
            cost:           0.5,
        );
    }

    private function knowledge(
        string               $shotId,
        int                  $ordinal,
        ?StoryBeat           $beat = null,
        ?CameraConfiguration $camera = null,
        array                $findingCodes = [],
    ): ShotKnowledge {
        return new ShotKnowledge(
            ordinal:      $ordinal,
            shotId:       $shotId,
            beat:         $beat,
            camera:       $camera,
            findingCodes: $findingCodes,
        );
    }

    private function camera(ShotType $shotType, LensType $lens): CameraConfiguration
    {
        return new CameraConfiguration(
            shotType: $shotType,
            angle:    CameraAngle::EYE_LEVEL,
            movement: CameraMovement::STATIC,
            lens:     $lens,
        );
    }
}
