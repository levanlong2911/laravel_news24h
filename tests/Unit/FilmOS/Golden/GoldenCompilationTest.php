<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Golden;

use App\Services\AI\FilmOS\Planning\PlanningIR;
use App\Services\AI\FilmOS\Prompt\PromptCompiler;
use App\Services\AI\FilmOS\Prompt\PromptLearningEngine;
use App\Services\AI\FilmOS\Prompt\PromptRuleEngine;
use App\Services\AI\FilmOS\Prompt\Rules\DurationCameraRule;
use App\Services\AI\FilmOS\Render\RenderIR;
use PHPUnit\Framework\TestCase;

/**
 * Golden contract tests for the PlanningIR → RenderIR compilation pipeline.
 *
 * Each fixture pair locks:
 *   - planning_ir.json  →  INPUT (hand-authored, traceId is a fixed golden value)
 *   - render_ir.json    →  EXPECTED OUTPUT (generated, then committed)
 *
 * Regenerate all render_ir.json fixtures after an intentional change:
 *   FILMOS_UPDATE_SNAPSHOTS=1 php artisan test tests/Unit/FilmOS/Golden/GoldenCompilationTest.php
 *
 * Fixtures live in: resources/filmos/golden/{domain}/
 * Commit the fixtures alongside the code change that produced them.
 */
final class GoldenCompilationTest extends TestCase
{
    private const GOLDEN_DIR = __DIR__ . '/../../../../resources/filmos/golden';
    private const UPDATE_ENV = 'FILMOS_UPDATE_SNAPSHOTS';

    private PromptCompiler       $compiler;
    private PromptRuleEngine     $ruleEngine;
    private PromptLearningEngine $learningEngine;

    protected function setUp(): void
    {
        $this->compiler       = new PromptCompiler();
        $this->ruleEngine     = new PromptRuleEngine([new DurationCameraRule()]);
        $this->learningEngine = new PromptLearningEngine();
    }

    // ── Snapshot tests ────────────────────────────────────────────────────────

    /** @test */
    public function sports_touchdown_compiles_to_expected_render_ir(): void
    {
        $this->assertGoldenCompilation('sports_touchdown');
    }

    /** @test */
    public function breaking_news_compiles_to_expected_render_ir(): void
    {
        $this->assertGoldenCompilation('breaking_news');
    }

    /** @test */
    public function finance_compiles_to_expected_render_ir(): void
    {
        $this->assertGoldenCompilation('finance');
    }

    // ── DurationCameraRule contract ───────────────────────────────────────────

    /** @test */
    public function duration_at_boundary_does_not_trigger_camera_override(): void
    {
        $ir       = $this->loadPlanningIR(self::GOLDEN_DIR . '/breaking_news/planning_ir.json');
        $renderIr = $this->compile($ir);

        $this->assertSame('medium_shot', $renderIr->renderInstructions['camera'] ?? null,
            'DurationCameraRule must not override camera when duration = 8 (boundary)');
    }

    /** @test */
    public function duration_above_boundary_triggers_camera_wide(): void
    {
        $ir       = $this->loadPlanningIR(self::GOLDEN_DIR . '/finance/planning_ir.json');
        $renderIr = $this->compile($ir);

        $this->assertSame('wide', $renderIr->renderInstructions['camera'] ?? null,
            'DurationCameraRule must override camera to "wide" when duration > 8');
    }

    // ── Pipeline integrity ────────────────────────────────────────────────────

    /** @test */
    public function render_ir_version_always_equals_1(): void
    {
        foreach (['sports_touchdown', 'breaking_news', 'finance'] as $domain) {
            $ir       = $this->loadPlanningIR(self::GOLDEN_DIR . "/{$domain}/planning_ir.json");
            $renderIr = $this->compile($ir);
            $this->assertSame(1, $renderIr->version, "RenderIR.version must be 1 for domain: {$domain}");
        }
    }

    /** @test */
    public function duration_seconds_matches_constraint(): void
    {
        foreach (['sports_touchdown' => 5, 'breaking_news' => 8, 'finance' => 10] as $domain => $expected) {
            $ir       = $this->loadPlanningIR(self::GOLDEN_DIR . "/{$domain}/planning_ir.json");
            $renderIr = $this->compile($ir);
            $this->assertSame($expected, $renderIr->durationSeconds,
                "durationSeconds mismatch for {$domain}");
        }
    }

    /** @test */
    public function shot_id_preserved_through_compilation(): void
    {
        foreach (['sports_touchdown' => 'hook_1', 'breaking_news' => 'lead_1', 'finance' => 'analysis_1'] as $domain => $expectedShotId) {
            $ir       = $this->loadPlanningIR(self::GOLDEN_DIR . "/{$domain}/planning_ir.json");
            $renderIr = $this->compile($ir);
            $this->assertSame($expectedShotId, $renderIr->shotId,
                "shotId must pass through unchanged for {$domain}");
        }
    }

    /** @test */
    public function trace_id_propagates_from_planning_ir_to_render_ir(): void
    {
        foreach (['sports_touchdown', 'breaking_news', 'finance'] as $domain) {
            $ir       = $this->loadPlanningIR(self::GOLDEN_DIR . "/{$domain}/planning_ir.json");
            $renderIr = $this->compile($ir);
            $this->assertSame($ir->traceId, $renderIr->traceId,
                "traceId must propagate unchanged through the pipeline for {$domain}");
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function assertGoldenCompilation(string $domain): void
    {
        $dir        = self::GOLDEN_DIR . "/{$domain}";
        $ir         = $this->loadPlanningIR($dir . '/planning_ir.json');
        $renderIr   = $this->compile($ir);
        $actualArr  = $this->renderIrToArray($renderIr);

        $fixturePath = $dir . '/render_ir.json';

        if (getenv(self::UPDATE_ENV)) {
            file_put_contents(
                $fixturePath,
                json_encode($actualArr, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n",
            );
            $this->addToAssertionCount(1);
            return;
        }

        $this->assertFileExists($fixturePath,
            "Fixture missing: run with FILMOS_UPDATE_SNAPSHOTS=1 to generate render_ir.json for {$domain}");

        $expected = json_decode(file_get_contents($fixturePath), true);

        $this->assertEquals(
            $this->sortRecursive($expected),
            $this->sortRecursive($actualArr),
            "Golden snapshot mismatch for [{$domain}].\n" .
            "Run with FILMOS_UPDATE_SNAPSHOTS=1 if the change is intentional.",
        );
    }

    private function compile(PlanningIR $ir): RenderIR
    {
        $graph = $this->compiler->compile($ir);
        $graph = $this->ruleEngine->apply($graph);
        return $this->learningEngine->compile($graph);
    }

    private function loadPlanningIR(string $path): PlanningIR
    {
        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return new PlanningIR(
            traceId:     (string) $data['traceId'],
            version:     (int) $data['version'],
            shotId:      (string) $data['shotId'],
            shotOrder:   (int) $data['shotOrder'],
            goalId:      (string) $data['goalId'],
            renderHints: $data['renderHints'] ?? [],
            constraints: $data['constraints'] ?? [],
            attributes:  $data['attributes']  ?? [],
        );
    }

    private function renderIrToArray(RenderIR $ir): array
    {
        return [
            'traceId'            => $ir->traceId,
            'version'            => $ir->version,
            'shotId'             => $ir->shotId,
            'durationSeconds'    => $ir->durationSeconds,
            'renderInstructions' => $ir->renderInstructions,
            'constraints'        => $ir->constraints,
            'metadata'           => $ir->metadata,
            'attributes'         => $ir->attributes,
        ];
    }

    private function sortRecursive(array $arr): array
    {
        ksort($arr);
        foreach ($arr as $k => $v) {
            if (is_array($v)) {
                $arr[$k] = $this->sortRecursive($v);
            }
        }
        return $arr;
    }
}
