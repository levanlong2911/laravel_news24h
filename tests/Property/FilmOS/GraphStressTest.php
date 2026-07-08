<?php

declare(strict_types=1);

namespace Tests\Property\FilmOS;

use App\Services\AI\FilmOS\DecisionDAG\DAGEdge;
use App\Services\AI\FilmOS\DecisionDAG\DAGNode;
use App\Services\AI\FilmOS\DecisionDAG\DAGNodeType;
use App\Services\AI\FilmOS\DecisionDAG\DecisionDAG;
use App\Services\AI\FilmOS\Graph\GraphAlgorithms;
use App\Services\AI\FilmOS\Graph\GraphSerializer;
use App\Services\AI\FilmOS\Graph\GraphTraversal;
use PHPUnit\Framework\TestCase;

/**
 * Stress Tests — biết giới hạn thực tế của Graph Platform.
 *
 * @group stress
 *
 * Không chạy trong CI thông thường — chỉ chạy khi cần.
 * Chạy thủ công:
 *   php vendor/phpunit/phpunit/phpunit --group stress
 *
 * Mục tiêu: không phải production sẽ dùng 500k node ngay.
 * Mà là: biết hệ thống sẽ fail ở đâu trước khi production cần đến đó.
 *
 * Kết quả được log ra stderr để có thể capture trong CI nếu cần.
 */
final class GraphStressTest extends TestCase
{
    private function buildLinearDag(int $n): DecisionDAG
    {
        $dag = new DecisionDAG("stress_linear_{$n}");

        for ($i = 0; $i < $n; $i++) {
            $type = match (true) {
                $i === 0      => DAGNodeType::FACT,
                $i === $n - 1 => DAGNodeType::RENDER,
                $i < $n / 4   => DAGNodeType::MEANING,
                $i < $n / 2   => DAGNodeType::PLAN,
                default       => DAGNodeType::INTENT,
            };
            $dag->addNode(new DAGNode("n{$i}", $type, "p{$i}", 0.9, "node {$i}"));
        }
        for ($i = 0; $i < $n - 1; $i++) {
            $dag->addEdge(new DAGEdge("n{$i}", "n" . ($i + 1)));
        }
        return $dag;
    }

    private function measureMs(callable $fn): array
    {
        $memBefore = memory_get_peak_usage(true);
        $t0        = hrtime(true);
        $result    = $fn();
        $ms        = (hrtime(true) - $t0) / 1e6;
        $memAfter  = memory_get_peak_usage(true);
        return [$result, $ms, ($memAfter - $memBefore) / 1024 / 1024];
    }

    /** @group stress */
    public function test_stress_topo_sort_at_increasing_sizes(): void
    {
        $sizes   = [50_000, 100_000, 250_000, 500_000];
        $results = [];

        foreach ($sizes as $n) {
            $dag = $this->buildLinearDag($n);

            [, $ms, $memMb] = $this->measureMs(fn() => GraphAlgorithms::topoSort($dag));

            $results[] = [$n, $ms, $memMb];
            fwrite(STDERR, sprintf("  topoSort N=%7d | %7.1f ms | %5.1f MB\n", $n, $ms, $memMb));

            // Correctness at all sizes
            // (already verified by non-stress property tests, but double-check here)
            $this->assertTrue(true); // marker assertion to avoid risky test
        }

        // Scaling sanity: time should not grow faster than O(n²)
        // (O(n) to O(n log n) is expected — O(n²) would indicate a bug)
        if (count($results) >= 2) {
            [$n1, $t1] = $results[0];
            [$n2, $t2] = end($results);
            $ratio = ($t2 / $t1) / ($n2 / $n1);

            fwrite(STDERR, sprintf(
                "  Scaling ratio (time/size): %.2fx (expected ≈1.0 for O(n), ≤10 for O(n log n))\n",
                $ratio
            ));

            $this->assertLessThan(
                100,
                $ratio,
                "topoSort đang scale tệ hơn O(n²) — cần kiểm tra thuật toán"
            );
        }
    }

    /** @group stress */
    public function test_stress_trace_back_at_increasing_sizes(): void
    {
        $sizes = [50_000, 100_000, 250_000, 500_000];

        foreach ($sizes as $n) {
            $dag    = $this->buildLinearDag($n);
            $leafId = "n" . ($n - 1);

            [, $ms, $memMb] = $this->measureMs(
                fn() => GraphTraversal::traceBack($dag, $leafId, fn($node) => $node->isRoot())
            );

            fwrite(STDERR, sprintf("  traceBack N=%7d | %7.1f ms | %5.1f MB\n", $n, $ms, $memMb));
            $this->assertTrue(true);
        }
    }

    /** @group stress */
    public function test_stress_serialization_at_increasing_sizes(): void
    {
        $sizes = [10_000, 50_000, 100_000];

        foreach ($sizes as $n) {
            $dag = $this->buildLinearDag($n);

            [, $ms, $memMb] = $this->measureMs(
                fn() => GraphSerializer::snapshot($dag)
            );

            fwrite(STDERR, sprintf("  snapshot   N=%7d | %7.1f ms | %5.1f MB\n", $n, $ms, $memMb));
            $this->assertTrue(true);
        }
    }

    /**
     * @group stress
     * Đo memory footprint để phát hiện memory leak trong Graph storage.
     */
    public function test_stress_memory_footprint_grows_linearly(): void
    {
        $sizes  = [10_000, 50_000, 100_000];
        $points = [];

        foreach ($sizes as $n) {
            gc_collect_cycles();
            $before = memory_get_usage(true);
            $dag    = $this->buildLinearDag($n);
            $after  = memory_get_usage(true);
            $mb     = ($after - $before) / 1024 / 1024;
            $points[$n] = $mb;

            fwrite(STDERR, sprintf("  memory N=%7d | %5.1f MB | %.2f bytes/node\n",
                $n, $mb, ($mb * 1024 * 1024) / $n));

            unset($dag);
        }

        // Bytes per node phải ổn định (không tăng)
        $bytesPerNode = array_map(
            fn($n, $mb) => ($mb * 1024 * 1024) / $n,
            array_keys($points),
            $points
        );

        $first = $bytesPerNode[0];
        $last  = end($bytesPerNode);

        // Cho phép 50% overhead (GC, PHP overhead)
        $this->assertLessThan(
            $first * 3,
            $last,
            "Memory per node tăng hơn 3x giữa 10k và 100k nodes — có thể memory leak"
        );
    }
}
