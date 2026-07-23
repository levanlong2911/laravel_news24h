<?php

namespace Tests\Video\Analysis;

use App\Video\Analysis\ConfidenceAnalyzer;
use PHPUnit\Framework\TestCase;

class ConfidenceAnalyzerTest extends TestCase
{
    private function minimalPlan(array $scenes = [['id' => 's1']]): array
    {
        return ['scenes' => $scenes, 'continuity' => ['prohibitions' => []]];
    }

    public function test_empty_plan_scores_zero(): void
    {
        $report = (new ConfidenceAnalyzer())->analyze($this->minimalPlan());

        $this->assertSame(0.0, $report->coverageScore);
        $this->assertSame([], $report->coverageLayers);
        $this->assertCount(8, $report->missingLayers);
    }

    public function test_camera_path_present_when_any_scene_has_movement(): void
    {
        $plan = $this->minimalPlan([['id' => 's1', 'camera' => ['movement' => 'ORBIT']]]);

        $report = (new ConfidenceAnalyzer())->analyze($plan);

        $this->assertContains('camera_path', $report->coverageLayers);
    }

    public function test_objective_and_visual_style_read_directly_from_scene(): void
    {
        $plan = $this->minimalPlan([['id' => 's1', 'objective' => 'watch the hull take shape']]);

        $report = (new ConfidenceAnalyzer())->analyze($plan);

        $this->assertContains('objective', $report->coverageLayers);
        $this->assertContains('visual_style', $report->missingLayers);
    }

    public function test_director_notes_layers_read_from_any_scene(): void
    {
        $plan = $this->minimalPlan([
            ['id' => 's1'],
            ['id' => 's2', 'director_notes' => [
                'primary' => ['type' => 'lift', 'actor' => 'crane'],
                'secondary' => [['type' => 'signal', 'actor' => 'crane']],
                'micro_physics' => ['the lifting cable holds under visible tension'],
            ]],
        ]);

        $report = (new ConfidenceAnalyzer())->analyze($plan);

        $this->assertContains('primary', $report->coverageLayers);
        $this->assertContains('secondary', $report->coverageLayers);
        $this->assertContains('micro_physics', $report->coverageLayers);
    }

    public function test_environment_read_from_root_not_scene(): void
    {
        $plan = $this->minimalPlan();
        $plan['world_environment'] = ['weather' => 'RAIN'];

        $report = (new ConfidenceAnalyzer())->analyze($plan);

        $this->assertContains('environment', $report->coverageLayers);
    }

    public function test_negative_read_from_continuity_prohibitions(): void
    {
        $plan = $this->minimalPlan();
        $plan['continuity']['prohibitions'] = [
            ['entity_id' => 'moonrise', 'attribute' => 'domes', 'value' => false, 'reason' => 'refit'],
        ];

        $report = (new ConfidenceAnalyzer())->analyze($plan);

        $this->assertContains('negative', $report->coverageLayers);
    }

    public function test_full_coverage_scores_one(): void
    {
        $plan = [
            'scenes' => [[
                'id' => 's1', 'objective' => 'x', 'visual_style' => 'x',
                'camera' => ['movement' => 'ORBIT'],
                'director_notes' => [
                    'primary' => ['type' => 'lift', 'actor' => 'crane'],
                    'secondary' => [['type' => 'signal', 'actor' => 'crane']],
                    'micro_physics' => ['x'],
                ],
            ]],
            'world_environment' => ['weather' => 'RAIN'],
            'continuity' => ['prohibitions' => [['entity_id' => 'x', 'attribute' => 'y', 'value' => false, 'reason' => 'z']]],
        ];

        $report = (new ConfidenceAnalyzer())->analyze($plan);

        $this->assertSame(1.0, $report->coverageScore);
        $this->assertSame([], $report->missingLayers);
    }

    // ---- implementedCoverageScore: tách visual_style (cố ý chưa xây) khỏi coverage ----

    public function test_empty_plan_implemented_coverage_also_zero(): void
    {
        $report = (new ConfidenceAnalyzer())->analyze($this->minimalPlan());

        $this->assertSame(0.0, $report->implementedCoverageScore);
    }

    public function test_implemented_coverage_ignores_visual_style_gap(): void
    {
        // 7/7 layer ĐÃ XÂY có data, chỉ thiếu visual_style (chưa xây) ->
        // coverageScore lệch (7/8) nhưng implementedCoverageScore phải = 1.0.
        $plan = [
            'scenes' => [[
                'id' => 's1', 'objective' => 'x',
                'camera' => ['movement' => 'ORBIT'],
                'director_notes' => [
                    'primary' => ['type' => 'lift', 'actor' => 'crane'],
                    'secondary' => [['type' => 'signal', 'actor' => 'crane']],
                    'micro_physics' => ['x'],
                ],
            ]],
            'world_environment' => ['weather' => 'RAIN'],
            'continuity' => ['prohibitions' => [['entity_id' => 'x', 'attribute' => 'y', 'value' => false, 'reason' => 'z']]],
        ];

        $report = (new ConfidenceAnalyzer())->analyze($plan);

        $this->assertSame(0.875, $report->coverageScore); // 7/8
        $this->assertSame(1.0, $report->implementedCoverageScore); // 7/7
    }
}
