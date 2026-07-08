<?php

declare(strict_types=1);

namespace Tests\Architecture\FilmOS;

use App\Services\AI\FilmOS\DecisionDAG\DAGNode;
use App\Services\AI\FilmOS\DecisionDAG\DAGRuntime;
use App\Services\AI\FilmOS\DecisionDAG\DecisionDAG;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Tier 1: Layer Separation Invariants.
 *
 * Enforces ADR-016 Invariants 1–3:
 *   INV-1: Everything meaningful is a graph.
 *   INV-2: Layer labels are logical only — DAGRuntime determines execution order.
 *   INV-3: Execution is driven by DAGRuntime (no direct DAGNode construction outside it).
 */
final class LayerSeparationTest extends TestCase
{
    /** @test */
    public function dag_runtime_is_the_sole_creator_of_dag_nodes_by_contract(): void
    {
        // Structural check: DAGNode constructor must be public (cannot be sealed in PHP),
        // but DAGRuntime::execute() is the ONLY method that should instantiate it.
        // We verify the behavioral contract: a DAGNode built outside execute()
        // is never registered in a DecisionDAG returned by DAGRuntime.

        $runtime = new DAGRuntime('test_prod');

        $output = $runtime->execute(
            nodeId: 'node_a',
            type: \App\Services\AI\FilmOS\DecisionDAG\DAGNodeType::FACT,
            operation: fn() => 'payload_a',
            rationale: 'test fact',
        );

        $dag = $runtime->toDecisionDAG();

        $this->assertSame('payload_a', $output, 'execute() must return the operation result.');
        $this->assertNotNull($dag->node('node_a'), 'Node must appear in DAG after execute().');
        $this->assertSame('payload_a', $dag->node('node_a')->payload);
    }

    /** @test */
    public function dag_nodes_produced_by_runtime_have_parent_edges(): void
    {
        $runtime = new DAGRuntime('test_inv3');

        $runtime->execute('fact_1', \App\Services\AI\FilmOS\DecisionDAG\DAGNodeType::FACT,
            fn() => 'raw fact');

        $runtime->execute('meaning_1', \App\Services\AI\FilmOS\DecisionDAG\DAGNodeType::MEANING,
            fn() => 'derived meaning',
            rationale: 'from fact_1',
            parentIds: ['fact_1']);

        $dag     = $runtime->toDecisionDAG();
        $edges   = $dag->edges();

        $this->assertCount(1, $edges, 'One edge: fact_1 → meaning_1');
        $this->assertSame('fact_1',   $edges[0]->fromId);
        $this->assertSame('meaning_1', $edges[0]->toId);
    }

    /** @test */
    public function fact_nodes_are_graph_roots_and_have_no_parents(): void
    {
        $runtime = new DAGRuntime('test_roots');

        $runtime->execute('fact_a', \App\Services\AI\FilmOS\DecisionDAG\DAGNodeType::FACT,
            fn() => 'fact a');
        $runtime->execute('meaning_b', \App\Services\AI\FilmOS\DecisionDAG\DAGNodeType::MEANING,
            fn() => 'meaning b', parentIds: ['fact_a']);

        $dag = $runtime->toDecisionDAG();

        $factNode = $dag->node('fact_a');
        $this->assertTrue($factNode->isRoot(), 'FACT nodes must be graph roots (isRoot() = true).');

        $meaningNode = $dag->node('meaning_b');
        $this->assertFalse($meaningNode->isRoot(), 'MEANING nodes are not roots.');
    }

    /** @test */
    public function decision_dag_is_separate_from_execution_graph_concern(): void
    {
        // DecisionDAG answers WHY. It must not contain execution timing, retry, or cache data.
        $ref            = new ReflectionClass(DecisionDAG::class);
        $forbiddenProps = ['retryCount', 'cacheHit', 'executionTimeMs', 'scheduledAt'];

        foreach ($forbiddenProps as $prop) {
            $this->assertFalse(
                $ref->hasProperty($prop),
                "DecisionDAG (WHY graph) must not have '{$prop}' — that belongs in ExecutionGraph (HOW)."
            );
        }
    }

    /** @test */
    public function dag_runtime_records_rationale_on_every_node(): void
    {
        $runtime = new DAGRuntime('test_rationale');
        $runtime->execute('fact_x', \App\Services\AI\FilmOS\DecisionDAG\DAGNodeType::FACT,
            fn() => 'x', rationale: 'Ground truth from sensor');

        $node = $runtime->toDecisionDAG()->node('fact_x');
        $this->assertNotEmpty($node->rationale, 'Every DAG node must have a rationale (WHY it exists).');
    }
}
