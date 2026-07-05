<?php

namespace Tests\Unit\AFOS\Passes;

use App\Services\AI\AFOS\Passes\Camera\SimpleCameraPass;
use App\Services\AI\AFOS\Passes\Config\CameraPassConfig;
use App\Services\AI\AFOS\Passes\Config\CompositionPassConfig;
use App\Services\AI\AFOS\Passes\Composition\SimpleCompositionPass;
use App\Services\AI\AFOS\Passes\Pipeline\DependencyGraph;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineDefinition;
use App\Services\AI\AFOS\Passes\Prompt\KlingPromptPlanningPass;
use App\Services\AI\AFOS\Passes\Stages\BackendStage;
use App\Services\AI\AFOS\Passes\Stages\CameraValidationStage;
use App\Services\AI\AFOS\Passes\Stages\ShotValidationStage;
use App\Services\AI\AFOS\Passes\Stages\Tier1Stage;
use App\Services\AI\AFOS\Passes\Stages\Tier2Stage;
use App\Services\AI\AFOS\Passes\Stages\Tier3Stage;
use PHPUnit\Framework\TestCase;

class DependencyGraphTest extends TestCase
{
    private function standardGraph(): DependencyGraph
    {
        return DependencyGraph::build(PipelineDefinition::standard());
    }

    public function test_standard_pipeline_has_no_cycle(): void
    {
        $this->assertFalse($this->standardGraph()->hasCycle());
    }

    public function test_standard_pipeline_topological_order_has_six_stages(): void
    {
        $order = $this->standardGraph()->topologicalOrder();
        $this->assertCount(6, $order);
    }

    public function test_shot_validation_is_entry_point(): void
    {
        $entries = $this->standardGraph()->entryPoints();
        $this->assertContains('ShotValidationStage', $entries);
    }

    public function test_tier1_upstream_is_empty(): void
    {
        // Tier1 reads ShotGoalIR, DirectorProfile, CinematographyProfile —
        // all produced by the initial PipelineState, not by any stage.
        $graph = $this->standardGraph();
        $this->assertEmpty($graph->upstreamsOf('Tier1Stage'));
    }

    public function test_tier2_upstream_is_tier1(): void
    {
        $graph = $this->standardGraph();
        $this->assertContains('Tier1Stage', $graph->upstreamsOf('Tier2Stage'));
    }

    public function test_tier3_upstream_contains_tier2(): void
    {
        $graph = $this->standardGraph();
        $this->assertContains('Tier2Stage', $graph->upstreamsOf('Tier3Stage'));
    }

    public function test_tier3_upstream_contains_tier1_for_composition(): void
    {
        // Tier3 reads CompositionIR (produced by Tier1) AND CameraIR (produced by Tier2)
        $graph = $this->standardGraph();
        $ups   = $graph->upstreamsOf('Tier3Stage');
        $this->assertContains('Tier1Stage', $ups);
        $this->assertContains('Tier2Stage', $ups);
    }

    public function test_backend_stage_upstream_is_tier3(): void
    {
        $graph = $this->standardGraph();
        $this->assertContains('Tier3Stage', $graph->upstreamsOf('BackendStage'));
    }

    public function test_tier1_downstream_contains_tier2_and_tier3(): void
    {
        $graph = $this->standardGraph();
        $downs = $graph->downstreamsOf('Tier1Stage');
        $this->assertContains('Tier2Stage', $downs);
        $this->assertContains('Tier3Stage', $downs);  // Tier3 reads CompositionIR
    }

    public function test_topological_order_respects_tier1_before_tier2(): void
    {
        $order  = $this->standardGraph()->topologicalOrder();
        $t1Pos  = array_search('Tier1Stage', $order);
        $t2Pos  = array_search('Tier2Stage', $order);
        $this->assertLessThan($t2Pos, $t1Pos, 'Tier1 must appear before Tier2 in topological order');
    }

    public function test_topological_order_respects_tier2_before_tier3(): void
    {
        $order = $this->standardGraph()->topologicalOrder();
        $this->assertLessThan(
            array_search('Tier3Stage', $order),
            array_search('Tier2Stage', $order)
        );
    }

    public function test_describe_returns_nodes_and_edges(): void
    {
        $desc = $this->standardGraph()->describe();
        $this->assertArrayHasKey('nodes',  $desc);
        $this->assertArrayHasKey('edges',  $desc);
        $this->assertArrayHasKey('has_cycle', $desc);
        $this->assertArrayHasKey('entry_points', $desc);
        $this->assertFalse($desc['has_cycle']);
    }

    public function test_describe_nodes_include_capabilities(): void
    {
        $desc  = $this->standardGraph()->describe();
        $tier1 = $desc['nodes']['Tier1Stage'] ?? null;
        $this->assertNotNull($tier1);
        $this->assertContains('pure',      $tier1['capabilities']);
        $this->assertContains('cacheable', $tier1['capabilities']);
    }

    public function test_build_graph_via_pipeline_definition(): void
    {
        $graph = PipelineDefinition::standard()->buildGraph();
        $this->assertInstanceOf(DependencyGraph::class, $graph);
        $this->assertFalse($graph->hasCycle());
    }

    public function test_single_stage_pipeline_has_no_edges(): void
    {
        $def   = PipelineDefinition::fromStages(new ShotValidationStage());
        $graph = DependencyGraph::build($def);

        $this->assertEmpty($graph->upstreamsOf('ShotValidationStage'));
        $this->assertEmpty($graph->downstreamsOf('ShotValidationStage'));
        $this->assertFalse($graph->hasCycle());
        $this->assertSame(['ShotValidationStage'], $graph->topologicalOrder());
    }
}
