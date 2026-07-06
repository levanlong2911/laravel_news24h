<?php

namespace Tests\Unit\AFOS\Passes\Optimizer;

use App\Services\AI\AFOS\Passes\Optimizer\DependencyLevelBuilder;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineDefinition;
use PHPUnit\Framework\TestCase;

class DependencyLevelBuilderTest extends TestCase
{
    // ── Empty / trivial ───────────────────────────────────────────────────────

    public function test_empty_stages_returns_empty_level_indices(): void
    {
        $this->assertSame([], DependencyLevelBuilder::levelIndices([]));
    }

    public function test_empty_stages_returns_empty_groups(): void
    {
        $this->assertSame([], DependencyLevelBuilder::groupByLevel([]));
    }

    public function test_single_stage_lands_on_level_zero(): void
    {
        $stages  = [PipelineDefinition::standard()->stages()[8]]; // BackendStage (no IR deps)
        $indices = DependencyLevelBuilder::levelIndices($stages);

        $this->assertSame([0 => 0], $indices);
    }

    public function test_single_stage_group_has_one_key(): void
    {
        $stages = [PipelineDefinition::standard()->stages()[8]]; // BackendStage
        $groups = DependencyLevelBuilder::groupByLevel($stages);

        $this->assertCount(1, $groups);
        $this->assertArrayHasKey(0, $groups);
    }

    // ── Standard pipeline ─────────────────────────────────────────────────────

    public function test_standard_pipeline_produces_six_distinct_levels(): void
    {
        $stages  = PipelineDefinition::standard()->stages();
        $groups  = DependencyLevelBuilder::groupByLevel($stages);

        $this->assertCount(6, $groups);
    }

    public function test_standard_pipeline_level_zero_has_two_stages(): void
    {
        $stages  = PipelineDefinition::standard()->stages();
        $groups  = DependencyLevelBuilder::groupByLevel($stages);

        $this->assertCount(2, $groups[0]);
    }

    public function test_standard_pipeline_level_zero_contains_shot_validation_and_tier1(): void
    {
        $stages = PipelineDefinition::standard()->stages();
        $groups = DependencyLevelBuilder::groupByLevel($stages);
        $names  = array_map(fn($s) => $s->name(), $groups[0]);

        $this->assertContains('ShotValidationStage', $names);
        $this->assertContains('Tier1Stage', $names);
    }

    public function test_standard_pipeline_level_one_has_motion_beat_and_tier2(): void
    {
        $stages = PipelineDefinition::standard()->stages();
        $groups = DependencyLevelBuilder::groupByLevel($stages);
        $names  = array_map(fn($s) => $s->name(), $groups[1]);

        $this->assertCount(2, $groups[1]);
        $this->assertContains('MotionBeatStage', $names);
        $this->assertContains('Tier2Stage', $names);
    }

    public function test_standard_pipeline_level_two_has_camera_arc_and_camera_validation(): void
    {
        $stages = PipelineDefinition::standard()->stages();
        $groups = DependencyLevelBuilder::groupByLevel($stages);
        $names  = array_map(fn($s) => $s->name(), $groups[2]);

        $this->assertContains('CameraArcStage', $names);
        $this->assertContains('CameraValidationStage', $names);
    }

    public function test_standard_pipeline_level_three_contains_only_freeze_stage(): void
    {
        $stages = PipelineDefinition::standard()->stages();
        $groups = DependencyLevelBuilder::groupByLevel($stages);

        $this->assertCount(1, $groups[3]);
        $this->assertSame('FreezeStage', $groups[3][0]->name());
    }

    public function test_standard_pipeline_level_five_contains_only_backend(): void
    {
        $stages = PipelineDefinition::standard()->stages();
        $groups = DependencyLevelBuilder::groupByLevel($stages);

        $this->assertCount(1, $groups[5]);
        $this->assertSame('BackendStage', $groups[5][0]->name());
    }

    // ── Level indices array ───────────────────────────────────────────────────

    public function test_level_indices_count_matches_stage_count(): void
    {
        $stages  = PipelineDefinition::standard()->stages();
        $indices = DependencyLevelBuilder::levelIndices($stages);

        $this->assertCount(count($stages), $indices);
    }

    public function test_level_indices_are_non_negative(): void
    {
        $stages  = PipelineDefinition::standard()->stages();
        $indices = DependencyLevelBuilder::levelIndices($stages);

        foreach ($indices as $level) {
            $this->assertGreaterThanOrEqual(0, $level);
        }
    }

    public function test_level_indices_are_monotonically_valid(): void
    {
        // For standard pipeline, producer always has lower level than consumer.
        // New 9-stage order: ShotValidation(0) Tier1(1) MotionBeat(2) Tier2(3)
        //   CameraArc(4) CameraValidation(5) FreezeStage(6) Tier3(7) Backend(8)
        $stages  = PipelineDefinition::standard()->stages();
        $indices = DependencyLevelBuilder::levelIndices($stages);

        // Tier1(idx 1) = L0, Tier2(idx 3) = L1
        $this->assertLessThan($indices[3], $indices[1], 'Tier1 must have lower level than Tier2');
        // Tier2(idx 3) = L1, Tier3(idx 7) = L4
        $this->assertLessThan($indices[7], $indices[3], 'Tier2 must have lower level than Tier3');
        // Tier3(idx 7) = L4, Backend(idx 8) = L5
        $this->assertLessThan($indices[8], $indices[7], 'Tier3 must have lower level than Backend');
    }

    // ── groupByLevel: key ordering ────────────────────────────────────────────

    public function test_group_keys_are_sorted_ascending(): void
    {
        $stages = PipelineDefinition::standard()->stages();
        $groups = DependencyLevelBuilder::groupByLevel($stages);
        $keys   = array_keys($groups);

        $this->assertSame($keys, range(0, count($groups) - 1));
    }

    public function test_all_stages_appear_in_groups(): void
    {
        $stages = PipelineDefinition::standard()->stages();
        $groups = DependencyLevelBuilder::groupByLevel($stages);

        $allNames = [];
        foreach ($groups as $group) {
            foreach ($group as $stage) {
                $allNames[] = $stage->name();
            }
        }

        $this->assertCount(count($stages), $allNames);
    }
}
