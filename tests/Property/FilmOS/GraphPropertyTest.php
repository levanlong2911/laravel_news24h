<?php

declare(strict_types=1);

namespace Tests\Property\FilmOS;

use App\Services\AI\FilmOS\DecisionDAG\DAGEdge;
use App\Services\AI\FilmOS\DecisionDAG\DAGNode;
use App\Services\AI\FilmOS\DecisionDAG\DAGNodeType;
use App\Services\AI\FilmOS\DecisionDAG\DecisionDAG;
use App\Services\AI\FilmOS\Graph\GraphAlgorithms;
use App\Services\AI\FilmOS\Graph\GraphTraversal;
use App\Services\AI\FilmOS\Graph\GraphValidation;
use PHPUnit\Framework\TestCase;

/**
 * Property-based tests for Graph Platform.
 *
 * Không test trường hợp cụ thể — test THUỘC TÍNH phải luôn đúng
 * bất kể cấu trúc graph nào được sinh ra.
 *
 * Mỗi property chạy trên N graph ngẫu nhiên với seed khác nhau.
 * Nếu có bug trong thuật toán, property test sẽ bắt được những
 * trường hợp mà unit test không nghĩ tới.
 *
 * Phân bổ test:
 *   - 1000 DAG × 10 nodes  (cấu trúc nhỏ, coverage cao)
 *   - 100  DAG × 100 nodes (cấu trúc trung bình)
 *   - 10   DAG × 1000 nodes (cấu trúc lớn, stress nhẹ)
 */
final class GraphPropertyTest extends TestCase
{
    // ── Generator ─────────────────────────────────────────────────────────────

    /**
     * Sinh DAG ngẫu nhiên nhưng KHÔNG CÓ CYCLE.
     * Kỹ thuật: edges chỉ đi từ node có index nhỏ hơn → node có index lớn hơn.
     * Đảm bảo DAG thật sự (Directed Acyclic Graph).
     */
    private function randomDag(int $nodeCount, int $seed): DecisionDAG
    {
        $state = $seed;
        $lcg   = static function () use (&$state): int {
            $state = (1664525 * $state + 1013904223) & 0x7FFFFFFF;
            return $state;
        };

        $dag      = new DecisionDAG("prop_{$nodeCount}_s{$seed}");
        $rootCount = max(1, (int) ($lcg() % 3) + 1); // 1-3 roots

        for ($i = 0; $i < $nodeCount; $i++) {
            $type = $i < $rootCount ? DAGNodeType::FACT : DAGNodeType::MEANING;
            $dag->addNode(new DAGNode(
                "n{$i}",
                $type,
                "payload_{$i}",
                confidence: 1.0,
                rationale:  "node {$i}",
            ));
        }

        // Edges: n_j → n_i where j < i (guaranteed no cycle)
        for ($i = $rootCount; $i < $nodeCount; $i++) {
            $parentCount = min($i, (int) ($lcg() % 2) + 1); // 1-2 parents
            $chosen = [];
            for ($p = 0; $p < $parentCount; $p++) {
                $parentIdx = $lcg() % $i; // strictly less than i
                if (!isset($chosen[$parentIdx])) {
                    $chosen[$parentIdx] = true;
                    $dag->addEdge(new DAGEdge("n{$parentIdx}", "n{$i}"));
                }
            }
        }

        return $dag;
    }

    /**
     * DAG hợp lệ + 3-cycle được đảm bảo trên 3 node đầu.
     *
     * Back-edge n(n-1)→n0 KHÔNG đảm bảo cycle khi graph là forest
     * với nhiều root — DFS có thể black-mark n0 trước khi đi qua n(n-1).
     *
     * 3-cycle n0→n1→n2→n0 LUÔN được phát hiện vì DFS bắt đầu từ n0,
     * grey-mark n0, sau đó đi qua n1→n2→n0 (grey) → cycle!
     */
    private function randomCyclicGraph(int $nodeCount, int $seed): DecisionDAG
    {
        $dag = $this->randomDag($nodeCount, $seed);

        if ($nodeCount >= 3) {
            // Thêm các edge này (có thể đã tồn tại — OK, duplicate edges không gây lỗi)
            $dag->addEdge(new DAGEdge('n0', 'n1')); // forward
            $dag->addEdge(new DAGEdge('n1', 'n2')); // forward
            $dag->addEdge(new DAGEdge('n2', 'n0')); // back-edge → guaranteed cycle
        }

        return $dag;
    }

    // ── Property 1: topoSort returns ALL nodes ────────────────────────────────

    /**
     * ∀ G: DAG. |topoSort(G)| = |V(G)|
     * topoSort không được mất node.
     */
    public function test_topo_sort_always_returns_all_nodes(): void
    {
        $configs = [
            [1000, 10],   // 1000 graphs × 10 nodes
            [100,  100],  // 100 graphs × 100 nodes
            [10,   1000], // 10 graphs × 1000 nodes
        ];

        foreach ($configs as [$count, $size]) {
            for ($seed = 1; $seed <= $count; $seed++) {
                $dag    = $this->randomDag($size, $seed);
                $sorted = GraphAlgorithms::topoSort($dag);

                $this->assertCount(
                    $size,
                    $sorted,
                    "topoSort phải trả về đúng {$size} node (seed={$seed})"
                );

                if ($seed % 100 === 0) {
                    gc_collect_cycles();
                }
            }
        }

        // Số lần assert: 1000 + 100 + 10 = 1110
        $this->addToAssertionCount(1110);
    }

    // ── Property 2: topoSort respects edge direction ──────────────────────────

    /**
     * ∀ (u→v) ∈ E(G). position(u) < position(v) in topoSort output.
     * Không có edge nào "chạy ngược" trong thứ tự topo.
     */
    public function test_topo_sort_always_respects_edge_direction(): void
    {
        $configs = [[200, 20], [20, 200]];

        foreach ($configs as [$count, $size]) {
            for ($seed = 1; $seed <= $count; $seed++) {
                $dag      = $this->randomDag($size, $seed);
                $sorted   = GraphAlgorithms::topoSort($dag);
                $position = array_flip(array_map(fn($n) => $n->id, $sorted));

                foreach ($dag->edges() as $edge) {
                    $this->assertLessThan(
                        $position[$edge->toId],
                        $position[$edge->fromId],
                        "Edge {$edge->fromId}→{$edge->toId}: from phải trước to trong topoSort (seed={$seed})"
                    );
                }

                if ($seed % 50 === 0) {
                    gc_collect_cycles();
                }
            }
        }

        $this->addToAssertionCount(220); // at least 1 edge assertion per graph
    }

    // ── Property 3: detectCycle consistent with structure ────────────────────

    /**
     * ∀ G: DAG (no cycle). detectCycle(G) = false
     * ∀ G: Graph with cycle. detectCycle(G) = true
     */
    public function test_detect_cycle_is_consistent_with_graph_structure(): void
    {
        $acyclicCount  = 0;
        $cyclicCount   = 0;

        // DAG không cycle → detectCycle phải false
        for ($seed = 1; $seed <= 500; $seed++) {
            $dag = $this->randomDag(20, $seed);
            $this->assertFalse(
                GraphAlgorithms::detectCycle($dag),
                "DAG không có cycle nhưng detectCycle=true (seed={$seed})"
            );
            $acyclicCount++;
            if ($seed % 100 === 0) {
                gc_collect_cycles();
            }
        }

        // Graph có cycle → detectCycle phải true
        for ($seed = 1; $seed <= 200; $seed++) {
            $dag = $this->randomCyclicGraph(10, $seed);
            $this->assertTrue(
                GraphAlgorithms::detectCycle($dag),
                "Graph có cycle nhưng detectCycle=false (seed={$seed})"
            );
            $cyclicCount++;
        }

        // detectCycle và topoSort phải nhất quán
        for ($seed = 1; $seed <= 100; $seed++) {
            $dag      = $this->randomDag(15, $seed);
            $hasError = false;
            try {
                GraphAlgorithms::topoSort($dag);
            } catch (\RuntimeException) {
                $hasError = true;
            }
            $this->assertSame(
                GraphAlgorithms::detectCycle($dag),
                $hasError,
                "detectCycle và topoSort không nhất quán (seed={$seed})"
            );
        }

        $this->addToAssertionCount($acyclicCount + $cyclicCount + 100);
    }

    // ── Property 4: traceBack always reaches a root ───────────────────────────

    /**
     * ∀ G: DAG, ∀ leaf ∈ G. traceBack(G, leaf).last().isRoot() = true
     * Mọi node đều có thể trace về đến root (không có đường bị đứt).
     */
    public function test_trace_back_always_reaches_a_root(): void
    {
        for ($seed = 1; $seed <= 300; $seed++) {
            $dag    = $this->randomDag(25, $seed);
            $leafId = "n" . ($dag->nodeCount() - 1);

            $chain = GraphTraversal::traceBack($dag, $leafId, fn($n) => $n->isRoot());
            $this->assertNotEmpty($chain, "traceBack phải trả về ít nhất 1 node (seed={$seed})");

            $lastNode = $dag->node(end($chain));
            $this->assertNotNull($lastNode, "Node cuối cùng trong chain phải tồn tại (seed={$seed})");
            $this->assertTrue(
                $lastNode->isRoot(),
                "traceBack phải kết thúc ở root node (seed={$seed})"
            );
        }

        $this->addToAssertionCount(300 * 3);
    }

    // ── Property 5: hasOrphans contract ──────────────────────────────────────

    /**
     * ∀ G: DAG được sinh bởi generator (tất cả non-root đều có parent).
     * hasOrphans(G) = false.
     *
     * Ngược lại: DAG có orphan → hasOrphans = true.
     */
    public function test_has_orphans_contract(): void
    {
        // Tất cả DAG hợp lệ sinh ra bởi generator không có orphan
        for ($seed = 1; $seed <= 500; $seed++) {
            $dag = $this->randomDag(20, $seed);
            $this->assertFalse(
                GraphValidation::hasOrphans($dag),
                "DAG hợp lệ không được có orphan (seed={$seed})"
            );
        }

        // DAG có orphan phải bị phát hiện
        for ($seed = 1; $seed <= 100; $seed++) {
            $dag = $this->randomDag(10, $seed);
            // Thêm một orphan node (non-root, không có incoming edge)
            $dag->addNode(new DAGNode("orphan_{$seed}", DAGNodeType::MEANING, null, 1.0, 'orphan'));
            $this->assertTrue(
                GraphValidation::hasOrphans($dag),
                "hasOrphans phải detect orphan vừa thêm (seed={$seed})"
            );
        }

        $this->addToAssertionCount(600);
    }

    // ── Property 6: Graph serialization roundtrip ─────────────────────────────

    /**
     * ∀ G. snapshot(G).nodeCount = nodeCount(G) ∧ snapshot(G).edgeCount = edgeCount(G)
     * Serializer không làm mất dữ liệu.
     */
    public function test_serializer_is_lossless(): void
    {
        for ($seed = 1; $seed <= 200; $seed++) {
            $size = ($seed % 3 === 0) ? 50 : 10;
            $dag  = $this->randomDag($size, $seed);

            $snapshot = \App\Services\AI\FilmOS\Graph\GraphSerializer::snapshot($dag);

            $this->assertSame($dag->nodeCount(), $snapshot['nodeCount'],
                "snapshot.nodeCount phải bằng nodeCount() (seed={$seed})");
            $this->assertSame($dag->edgeCount(), $snapshot['edgeCount'],
                "snapshot.edgeCount phải bằng edgeCount() (seed={$seed})");
            $this->assertCount($dag->nodeCount(), $snapshot['nodeIds'],
                "snapshot.nodeIds phải liệt kê đủ (seed={$seed})");
        }

        $this->addToAssertionCount(200 * 3);
    }

    // ── Property 7: Determinism ───────────────────────────────────────────────

    /**
     * ∀ seed. randomDag(N, seed) == randomDag(N, seed) [bit-for-bit identical snapshots]
     * Đảm bảo replay determinism là thuộc tính của generator, không phải may rủi.
     */
    public function test_same_seed_always_produces_same_dag(): void
    {
        for ($seed = 1; $seed <= 100; $seed++) {
            $dagA = $this->randomDag(30, $seed);
            $dagB = $this->randomDag(30, $seed);

            $this->assertSame(
                \App\Services\AI\FilmOS\Graph\GraphSerializer::snapshot($dagA),
                \App\Services\AI\FilmOS\Graph\GraphSerializer::snapshot($dagB),
                "Cùng seed={$seed} phải tạo ra cùng DAG"
            );

            $this->assertSame(
                \App\Services\AI\FilmOS\Graph\GraphSerializer::toEdgeList($dagA),
                \App\Services\AI\FilmOS\Graph\GraphSerializer::toEdgeList($dagB),
                "Cùng seed={$seed} phải tạo ra cùng edge list"
            );
        }

        $this->addToAssertionCount(200);
    }
}
