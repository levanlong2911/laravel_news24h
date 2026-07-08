<?php

declare(strict_types=1);

namespace App\Console\Commands\FilmOS;

use App\Services\AI\FilmOS\DecisionDAG\DAGEdge;
use App\Services\AI\FilmOS\DecisionDAG\DAGNode;
use App\Services\AI\FilmOS\DecisionDAG\DAGNodeType;
use App\Services\AI\FilmOS\DecisionDAG\DecisionDAG;
use App\Services\AI\FilmOS\Graph\GraphAlgorithms;
use App\Services\AI\FilmOS\Graph\GraphTraversal;
use Illuminate\Console\Command;

/**
 * Phân tích chi phí bộ nhớ theo từng thành phần của Graph Platform.
 *
 * Trả lời câu hỏi: mỗi node/edge tốn bao nhiêu byte?
 * Phần nào của thuật toán chiếm nhiều RAM nhất?
 *
 * Dùng để:
 *   - Biết chính xác bottleneck trước khi optimize
 *   - Verify rằng optimize thực sự giảm memory
 *   - Detect memory leak (bytes/node tăng theo N → có leak)
 *
 * Usage:
 *   php artisan filmos:memory-profile
 *   php artisan filmos:memory-profile --sizes=1000,10000,50000
 */
class MemoryProfileCommand extends Command
{
    protected $signature = 'filmos:memory-profile
                            {--sizes=1000,5000,10000,50000 : Comma-separated sizes}';

    protected $description = 'Profile per-component memory cost of Graph Platform';

    public function handle(): int
    {
        $sizes = array_map('intval', explode(',', $this->option('sizes')));

        $this->info("FilmOS Memory Profiler");
        $this->info(sprintf("PHP %s | memory_limit=%s | %s", PHP_VERSION, ini_get('memory_limit'), date('Y-m-d H:i:s')));
        $this->newLine();

        $this->profileEmptyObject();
        $this->profilePerNodeCost($sizes);
        $this->profilePerEdgeCost($sizes);
        $this->profileAlgorithmOverhead($sizes);
        $this->profileAlgorithmComponents($sizes[array_key_last($sizes)] ?? 10000);

        return self::SUCCESS;
    }

    // ── Empty object baseline ─────────────────────────────────────────────────

    private function profileEmptyObject(): void
    {
        $this->info("── Object baseline ──────────────────────────────────");

        gc_collect_cycles();
        $before = memory_get_usage();
        $dag    = new DecisionDAG('baseline');
        $after  = memory_get_usage();

        $this->line(sprintf("  Empty DecisionDAG:   %d bytes", $after - $before));

        gc_collect_cycles();
        $before = memory_get_usage();
        $node   = new DAGNode('n0', DAGNodeType::FACT, null, 1.0, 'test');
        $after  = memory_get_usage();
        $this->line(sprintf("  Single DAGNode:      %d bytes", $after - $before));

        gc_collect_cycles();
        $before = memory_get_usage();
        $edge   = new DAGEdge('n0', 'n1');
        $after  = memory_get_usage();
        $this->line(sprintf("  Single DAGEdge:      %d bytes", $after - $before));

        $this->newLine();
        unset($dag, $node, $edge);
    }

    // ── Per-node cost ─────────────────────────────────────────────────────────

    private function profilePerNodeCost(array $sizes): void
    {
        $this->info("── Memory per node (nodes only, no edges) ───────────");

        $rows = [];
        foreach ($sizes as $n) {
            gc_collect_cycles();
            $before = memory_get_usage(true);
            $dag    = new DecisionDAG("nodes_only_{$n}");
            for ($i = 0; $i < $n; $i++) {
                $dag->addNode(new DAGNode("n{$i}", DAGNodeType::MEANING, "p{$i}", 0.9, "r{$i}"));
            }
            $after      = memory_get_usage(true);
            $totalMb    = ($after - $before) / 1024 / 1024;
            $bytesPerNode = ($after - $before) / $n;

            $rows[]     = [$n, number_format($totalMb, 2), number_format($bytesPerNode, 0)];
            unset($dag);
            gc_collect_cycles();
        }

        $this->table(['N', 'Total MB', 'Bytes/node'], $rows);
        $this->newLine();
    }

    // ── Per-edge cost ─────────────────────────────────────────────────────────

    private function profilePerEdgeCost(array $sizes): void
    {
        $this->info("── Memory per edge (linear graph: N nodes, N-1 edges) ─");

        $rows = [];
        foreach ($sizes as $n) {
            gc_collect_cycles();
            $before = memory_get_usage(true);
            $dag    = new DecisionDAG("linear_{$n}");
            for ($i = 0; $i < $n; $i++) {
                $dag->addNode(new DAGNode("n{$i}", DAGNodeType::MEANING, null, 0.9, 'r'));
            }
            $beforeEdges = memory_get_usage(true);
            for ($i = 0; $i < $n - 1; $i++) {
                $dag->addEdge(new DAGEdge("n{$i}", "n" . ($i + 1)));
            }
            $after        = memory_get_usage(true);
            $edgeMb       = ($after - $beforeEdges) / 1024 / 1024;
            $bytesPerEdge = ($after - $beforeEdges) / max(1, $n - 1);

            $rows[] = [$n, number_format($edgeMb, 2), number_format($bytesPerEdge, 0)];
            unset($dag);
            gc_collect_cycles();
        }

        $this->table(['N', 'Edge memory MB', 'Bytes/edge'], $rows);
        $this->newLine();
    }

    // ── Algorithm overhead ────────────────────────────────────────────────────

    private function profileAlgorithmOverhead(array $sizes): void
    {
        $this->info("── Peak memory overhead during algorithm execution ───");
        $this->line("  (peak during algo) - (graph size before algo) = overhead");

        $rows = [];
        foreach ($sizes as $n) {
            $dag = $this->buildLinearDag($n);
            gc_collect_cycles();

            $beforeAlgo = memory_get_usage(true);
            $peakBefore = memory_get_peak_usage(true);

            GraphAlgorithms::topoSort($dag);

            $peak    = memory_get_peak_usage(true);
            $overhead = ($peak - $peakBefore) / 1024 / 1024;
            $ratio   = $overhead / (($beforeAlgo) / 1024 / 1024);

            $rows[] = [
                $n,
                number_format($beforeAlgo / 1024 / 1024, 1),
                number_format($overhead, 1),
                number_format($ratio, 2) . 'x',
            ];

            unset($dag);
            gc_collect_cycles();
        }

        $this->table(['N', 'Graph MB', 'topoSort overhead MB', 'Overhead ratio'], $rows);
        $this->newLine();
    }

    // ── Per-structure cost inside algorithm ───────────────────────────────────

    private function profileAlgorithmComponents(int $n): void
    {
        $this->info("── Algorithm internal structure cost at N={$n} ─────────");

        $structures = [
            'string-keyed inDegree array' => function () use ($n) {
                $arr = [];
                for ($i = 0; $i < $n; $i++) {
                    $arr["n{$i}"] = 0;
                }
                return $arr;
            },
            'int-keyed inDegree array' => function () use ($n) {
                return array_fill(0, $n, 0);
            },
            'SplFixedArray inDegree' => function () use ($n) {
                $arr = new \SplFixedArray($n);
                for ($i = 0; $i < $n; $i++) {
                    $arr[$i] = 0;
                }
                return $arr;
            },
            'string-keyed children array' => function () use ($n) {
                $arr = [];
                for ($i = 0; $i < $n; $i++) {
                    $arr["n{$i}"] = ["n" . ($i + 1)];
                }
                return $arr;
            },
            'int-keyed children array' => function () use ($n) {
                $arr = [];
                for ($i = 0; $i < $n; $i++) {
                    $arr[$i] = [$i + 1];
                }
                return $arr;
            },
        ];

        $rows = [];
        foreach ($structures as $label => $builder) {
            gc_collect_cycles();
            $before = memory_get_usage(true);
            $obj    = $builder();
            $after  = memory_get_usage(true);
            $mb     = ($after - $before) / 1024 / 1024;
            $rows[] = [$label, number_format($mb, 2), number_format(($after - $before) / $n, 1)];
            unset($obj);
            gc_collect_cycles();
        }

        $this->table(['Structure', 'Total MB', 'Bytes/entry'], $rows);

        $this->newLine();
        $this->line("  Key insight: int-keyed vs string-keyed = biggest memory difference.");
        $this->line("  SplFixedArray further reduces overhead for sequential integer indices.");
        $this->newLine();
    }

    private function buildLinearDag(int $n): DecisionDAG
    {
        $dag = new DecisionDAG("mp_{$n}");
        for ($i = 0; $i < $n; $i++) {
            $dag->addNode(new DAGNode("n{$i}", DAGNodeType::MEANING, null, 0.9, 'r'));
        }
        for ($i = 0; $i < $n - 1; $i++) {
            $dag->addEdge(new DAGEdge("n{$i}", "n" . ($i + 1)));
        }
        return $dag;
    }
}
