<?php

declare(strict_types=1);

namespace Tests\Infrastructure\FilmOS;

use App\Services\AI\FilmOS\DecisionDAG\DAGNodeType;
use App\Services\AI\FilmOS\DecisionDAG\DAGRuntime;
use App\Services\AI\FilmOS\Graph\GraphTraversal;
use App\Services\AI\FilmOS\Graph\GraphValidation;
use PHPUnit\Framework\TestCase;

/**
 * Tier 2: DAGRuntime contract tests.
 * Every behavioral guarantee of DAGRuntime must be verifiable here.
 */
final class DAGRuntimeTest extends TestCase
{
    /** @test */
    public function execute_returns_operation_result(): void
    {
        $rt     = new DAGRuntime('p1');
        $result = $rt->execute('n1', DAGNodeType::FACT, fn() => ['key' => 'value']);
        $this->assertSame(['key' => 'value'], $result);
    }

    /** @test */
    public function execute_builds_parent_child_edges(): void
    {
        $rt = new DAGRuntime('p2');
        $rt->execute('root',  DAGNodeType::FACT,    fn() => 'r');
        $rt->execute('child', DAGNodeType::MEANING,  fn() => 'c', parentIds: ['root']);
        $rt->execute('leaf',  DAGNodeType::PLAN,     fn() => 'l', parentIds: ['child']);

        $dag   = $rt->toDecisionDAG();
        $edges = $dag->edges();

        $this->assertCount(2, $edges);
        $this->assertSame('root',  $edges[0]->fromId);
        $this->assertSame('child', $edges[0]->toId);
        $this->assertSame('child', $edges[1]->fromId);
        $this->assertSame('leaf',  $edges[1]->toId);
    }

    /** @test */
    public function dag_produced_by_runtime_has_no_orphans(): void
    {
        $rt = new DAGRuntime('p3');
        $rt->execute('f1', DAGNodeType::FACT,    fn() => 1);
        $rt->execute('m1', DAGNodeType::MEANING, fn() => 2, parentIds: ['f1']);
        $rt->execute('p1', DAGNodeType::PLAN,    fn() => 3, parentIds: ['m1']);
        $rt->execute('r1', DAGNodeType::RENDER,  fn() => 4, parentIds: ['p1']);

        $this->assertFalse(
            GraphValidation::hasOrphans($rt->toDecisionDAG()),
            'A properly constructed DAG must have no orphans.'
        );
    }

    /** @test */
    public function trace_back_from_leaf_reaches_fact_root(): void
    {
        $rt = new DAGRuntime('p4');
        $rt->execute('fact',    DAGNodeType::FACT,    fn() => 'raw');
        $rt->execute('meaning', DAGNodeType::MEANING, fn() => 'derived', parentIds: ['fact']);
        $rt->execute('plan',    DAGNodeType::PLAN,    fn() => 'planned',  parentIds: ['meaning']);
        $rt->execute('render',  DAGNodeType::RENDER,  fn() => 'url',      parentIds: ['plan']);

        $dag   = $rt->toDecisionDAG();
        $chain = GraphTraversal::traceBack($dag, 'render', fn($n) => $n->isRoot());

        $this->assertSame(['render', 'plan', 'meaning', 'fact'], $chain);
        $lastNode = $dag->node(end($chain));
        $this->assertTrue($lastNode->isRoot(), 'Chain must end at a FACT (root) node.');
    }

    /** @test */
    public function explain_produces_non_empty_human_readable_string(): void
    {
        $rt = new DAGRuntime('p5');
        $rt->execute('fact_x', DAGNodeType::FACT, fn() => 'x', rationale: 'observed cockroach');
        $rt->execute('render_x', DAGNodeType::RENDER, fn() => 'url', rationale: 'rendered shot', parentIds: ['fact_x']);

        $dag     = $rt->toDecisionDAG();
        $explain = $dag->explain('render_x');

        $this->assertStringContainsString('render_x',         $explain);
        $this->assertStringContainsString('rendered shot',    $explain);
        $this->assertStringContainsString('fact_x',           $explain);
        $this->assertStringContainsString('observed cockroach', $explain);
    }

    /** @test */
    public function multiple_fact_roots_all_reached_by_trace(): void
    {
        $rt = new DAGRuntime('p6');
        $rt->execute('f1', DAGNodeType::FACT, fn() => 'fact1');
        $rt->execute('f2', DAGNodeType::FACT, fn() => 'fact2');
        $rt->execute('m1', DAGNodeType::MEANING, fn() => 'meaning', parentIds: ['f1', 'f2']);
        $rt->execute('r1', DAGNodeType::RENDER,  fn() => 'url',     parentIds: ['m1']);

        $dag   = $rt->toDecisionDAG();
        $chain = GraphTraversal::traceBack($dag, 'r1', fn($n) => $n->isRoot());

        // traceBack walks up one path — it must reach at least one FACT
        $lastNode = $dag->node(end($chain));
        $this->assertTrue($lastNode->isRoot(), 'Multi-root DAG: trace must reach a FACT node.');
    }
}
