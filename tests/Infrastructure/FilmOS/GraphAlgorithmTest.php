<?php

declare(strict_types=1);

namespace Tests\Infrastructure\FilmOS;

use App\Services\AI\FilmOS\DecisionDAG\DAGEdge;
use App\Services\AI\FilmOS\DecisionDAG\DAGNode;
use App\Services\AI\FilmOS\DecisionDAG\DAGNodeType;
use App\Services\AI\FilmOS\DecisionDAG\DecisionDAG;
use App\Services\AI\FilmOS\Graph\GraphAlgorithms;
use App\Services\AI\FilmOS\Graph\GraphQuery;
use App\Services\AI\FilmOS\Graph\GraphSerializer;
use App\Services\AI\FilmOS\Graph\GraphTraversal;
use App\Services\AI\FilmOS\Graph\GraphValidation;
use PHPUnit\Framework\TestCase;

/**
 * Tier 2: Infrastructure Tests.
 *
 * Algorithm correctness on known graphs.
 * These tests must pass at all graph sizes — they define the contract.
 */
final class GraphAlgorithmTest extends TestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function linearDag(int $length, string $prefix = 'n'): DecisionDAG
    {
        // n0 → n1 → n2 → ... → n{length-1}
        $dag = new DecisionDAG("prod_test_{$prefix}_{$length}");
        for ($i = 0; $i < $length; $i++) {
            $dag->addNode(new DAGNode(
                "{$prefix}{$i}",
                $i === 0 ? DAGNodeType::FACT : DAGNodeType::MEANING,
                "payload_{$i}",
                confidence: 1.0,
                rationale: "step {$i}",
            ));
        }
        for ($i = 0; $i < $length - 1; $i++) {
            $dag->addEdge(new DAGEdge("{$prefix}{$i}", "{$prefix}" . ($i + 1)));
        }
        return $dag;
    }

    private function dagWithCycle(): DecisionDAG
    {
        $dag = new DecisionDAG('prod_cycle');
        foreach (['a', 'b', 'c'] as $id) {
            $dag->addNode(new DAGNode($id, DAGNodeType::FACT, null, 1.0, 'cycle test'));
        }
        $dag->addEdge(new DAGEdge('a', 'b'));
        $dag->addEdge(new DAGEdge('b', 'c'));
        $dag->addEdge(new DAGEdge('c', 'a')); // cycle
        return $dag;
    }

    private function diamondDag(): DecisionDAG
    {
        //   root
        //  ↙    ↘
        // left  right
        //  ↘    ↙
        //   sink
        $dag = new DecisionDAG('prod_diamond');
        foreach (['root', 'left', 'right', 'sink'] as $id) {
            $type = $id === 'root' ? DAGNodeType::FACT : DAGNodeType::MEANING;
            $dag->addNode(new DAGNode($id, $type, null, 1.0, $id));
        }
        $dag->addEdge(new DAGEdge('root', 'left'));
        $dag->addEdge(new DAGEdge('root', 'right'));
        $dag->addEdge(new DAGEdge('left', 'sink'));
        $dag->addEdge(new DAGEdge('right', 'sink'));
        return $dag;
    }

    // ── GraphAlgorithms ───────────────────────────────────────────────────────

    /** @test */
    public function topo_sort_returns_parents_before_children(): void
    {
        $dag    = $this->linearDag(5);
        $sorted = GraphAlgorithms::topoSort($dag);

        $this->assertCount(5, $sorted);
        $positions = array_flip(array_map(fn($n) => $n->id, $sorted));

        for ($i = 0; $i < 4; $i++) {
            $this->assertLessThan(
                $positions["n" . ($i + 1)],
                $positions["n{$i}"],
                "n{$i} must appear before n" . ($i + 1) . " in topoSort"
            );
        }
    }

    /** @test */
    public function topo_sort_on_diamond_graph_returns_root_first_sink_last(): void
    {
        $dag    = $this->diamondDag();
        $sorted = GraphAlgorithms::topoSort($dag);
        $ids    = array_map(fn($n) => $n->id, $sorted);

        $this->assertSame('root', $ids[0], 'Root must be first in topoSort');
        $this->assertSame('sink', $ids[count($ids) - 1], 'Sink must be last in topoSort');
    }

    /** @test */
    public function topo_sort_throws_on_cyclic_graph(): void
    {
        $this->expectException(\RuntimeException::class);
        GraphAlgorithms::topoSort($this->dagWithCycle());
    }

    /** @test */
    public function detect_cycle_returns_true_for_cyclic_graph(): void
    {
        $this->assertTrue(GraphAlgorithms::detectCycle($this->dagWithCycle()));
    }

    /** @test */
    public function detect_cycle_returns_false_for_dag(): void
    {
        $this->assertFalse(GraphAlgorithms::detectCycle($this->linearDag(10)));
        $this->assertFalse(GraphAlgorithms::detectCycle($this->diamondDag()));
    }

    // ── GraphTraversal ────────────────────────────────────────────────────────

    /** @test */
    public function trace_back_returns_full_chain_from_leaf_to_root(): void
    {
        $dag   = $this->linearDag(5); // n0(FACT) → n1 → n2 → n3 → n4
        $chain = GraphTraversal::traceBack($dag, 'n4', fn($n) => $n->isRoot());

        $this->assertSame(['n4', 'n3', 'n2', 'n1', 'n0'], $chain);
    }

    /** @test */
    public function trace_back_on_nonexistent_node_returns_empty(): void
    {
        $dag   = $this->linearDag(3);
        $chain = GraphTraversal::traceBack($dag, 'MISSING', fn($n) => $n->isRoot());

        $this->assertSame([], $chain);
    }

    /** @test */
    public function bfs_from_root_visits_all_reachable_nodes(): void
    {
        $dag   = $this->diamondDag();
        $nodes = GraphTraversal::bfs($dag, 'root');
        $ids   = array_map(fn($n) => $n->id, $nodes);

        $this->assertContains('left',  $ids);
        $this->assertContains('right', $ids);
        $this->assertContains('sink',  $ids);
        $this->assertCount(4, $nodes); // root + left + right + sink
    }

    // ── GraphValidation ───────────────────────────────────────────────────────

    /** @test */
    public function has_orphans_returns_false_for_well_formed_dag(): void
    {
        $this->assertFalse(GraphValidation::hasOrphans($this->linearDag(5)));
        $this->assertFalse(GraphValidation::hasOrphans($this->diamondDag()));
    }

    /** @test */
    public function has_orphans_returns_true_when_non_root_node_has_no_parents(): void
    {
        $dag = new DecisionDAG('prod_orphan');
        $dag->addNode(new DAGNode('root',   DAGNodeType::FACT,    null, 1.0, 'r'));
        $dag->addNode(new DAGNode('child',  DAGNodeType::MEANING, null, 1.0, 'c'));
        $dag->addNode(new DAGNode('orphan', DAGNodeType::MEANING, null, 1.0, 'o'));
        $dag->addEdge(new DAGEdge('root', 'child'));
        // 'orphan' has no incoming edge and is not FACT → orphan

        $this->assertTrue(GraphValidation::hasOrphans($dag));
        $orphanIds = GraphValidation::findOrphanIds($dag);
        $this->assertContains('orphan', $orphanIds);
        $this->assertNotContains('root', $orphanIds);   // FACT is root, not orphan
        $this->assertNotContains('child', $orphanIds);  // has parent
    }

    /** @test */
    public function has_cycles_returns_true_only_for_cyclic_graph(): void
    {
        $this->assertTrue(GraphValidation::hasCycles($this->dagWithCycle()));
        $this->assertFalse(GraphValidation::hasCycles($this->linearDag(10)));
    }

    // ── GraphQuery ────────────────────────────────────────────────────────────

    /** @test */
    public function ancestors_returns_all_upstream_nodes(): void
    {
        $dag       = $this->linearDag(5); // n0→n1→n2→n3→n4
        $ancestors = GraphQuery::ancestors($dag, 'n4');
        $ids       = array_map(fn($n) => $n->id, $ancestors);

        $this->assertContains('n0', $ids);
        $this->assertContains('n1', $ids);
        $this->assertContains('n2', $ids);
        $this->assertContains('n3', $ids);
        $this->assertNotContains('n4', $ids);
    }

    /** @test */
    public function descendants_returns_all_downstream_nodes(): void
    {
        $dag         = $this->diamondDag();
        $descendants = GraphQuery::descendants($dag, 'root');
        $ids         = array_map(fn($n) => $n->id, $descendants);

        $this->assertContains('left',  $ids);
        $this->assertContains('right', $ids);
        $this->assertContains('sink',  $ids);
        $this->assertNotContains('root', $ids);
    }

    /** @test */
    public function neighbors_returns_direct_children_only(): void
    {
        $dag       = $this->diamondDag();
        $neighbors = GraphQuery::neighbors($dag, 'root');
        $ids       = array_map(fn($n) => $n->id, $neighbors);

        $this->assertContains('left',  $ids);
        $this->assertContains('right', $ids);
        $this->assertNotContains('sink', $ids); // 2 hops away
    }

    /** @test */
    public function common_ancestor_finds_nearest_shared_ancestor(): void
    {
        $dag = $this->diamondDag();
        $lca = GraphQuery::commonAncestor($dag, 'left', 'right');

        $this->assertNotNull($lca, 'left and right share ancestor: root');
        $this->assertSame('root', $lca->id);
    }

    /** @test */
    public function subgraph_returns_only_selected_nodes_and_internal_edges(): void
    {
        $dag  = $this->diamondDag();
        $sub  = GraphQuery::subgraph($dag, ['root', 'left', 'sink']);
        $ids  = array_map(fn($n) => $n->id, $sub['nodes']);
        $from = array_map(fn($e) => $e->fromId, $sub['edges']);

        $this->assertContains('root', $ids);
        $this->assertContains('left', $ids);
        $this->assertNotContains('right', $ids); // excluded

        // Edge root→left should be included; root→right should not
        $this->assertContains('root', $from); // root→left exists in subgraph
        foreach ($sub['edges'] as $e) {
            $this->assertNotSame('right', $e->fromId, 'right is excluded from subgraph');
            $this->assertNotSame('right', $e->toId,   'right is excluded from subgraph');
        }
    }

    /** @test */
    public function find_by_property_returns_nodes_matching_value(): void
    {
        $dag = $this->diamondDag();
        // root is FACT, others are MEANING
        $facts   = GraphQuery::findByProperty($dag, 'type', DAGNodeType::FACT);
        $meaning = GraphQuery::findByProperty($dag, 'type', DAGNodeType::MEANING);

        $this->assertCount(1, $facts);
        $this->assertSame('root', $facts[0]->id);
        $this->assertCount(3, $meaning);
    }

    // ── GraphSerializer ───────────────────────────────────────────────────────

    /** @test */
    public function snapshot_returns_correct_counts(): void
    {
        $dag = $this->linearDag(5); // 5 nodes, 4 edges
        $s   = GraphSerializer::snapshot($dag);

        $this->assertSame(5, $s['nodeCount']);
        $this->assertSame(4, $s['edgeCount']);
        $this->assertCount(5, $s['nodeIds']);
    }

    /** @test */
    public function to_json_produces_valid_json_with_all_nodes(): void
    {
        $dag  = $this->linearDag(3);
        $json = GraphSerializer::toJson($dag);
        $data = json_decode($json, true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('n0', $data);
        $this->assertArrayHasKey('n1', $data);
        $this->assertArrayHasKey('n2', $data);
        $this->assertNotEmpty($data['n0']['children']);
    }
}
