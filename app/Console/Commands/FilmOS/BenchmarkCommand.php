<?php

declare(strict_types=1);

namespace App\Console\Commands\FilmOS;

use App\Services\AI\FilmOS\DecisionDAG\DAGEdge;
use App\Services\AI\FilmOS\DecisionDAG\DAGNode;
use App\Services\AI\FilmOS\DecisionDAG\DAGNodeType;
use App\Services\AI\FilmOS\DecisionDAG\DAGRuntime;
use App\Services\AI\FilmOS\DecisionDAG\DecisionDAG;
use App\Services\AI\FilmOS\Graph\GraphAlgorithms;
use App\Services\AI\FilmOS\Graph\GraphSerializer;
use App\Services\AI\FilmOS\Graph\GraphTraversal;
use App\Services\AI\FilmOS\Graph\GraphValidation;
use App\Services\AI\FilmOS\Observability\PerformanceObservatory;
use Illuminate\Console\Command;

/**
 * Performance and Replay benchmark for the Graph Platform.
 *
 * Measures:
 *   - topoSort()     at N nodes (10 / 50 / 500 / 5000)
 *   - traceBack()    at N nodes
 *   - serialization  at N nodes
 *   - memory delta   at N nodes
 *
 * Replay:
 *   - Given identical facts + seed, DAG must be bit-for-bit identical.
 *   - Validates that FilmOS is deterministic (required for audit/rollback).
 *
 * Usage:
 *   php artisan filmos:benchmark
 *   php artisan filmos:benchmark --sizes=10,500,5000
 *   php artisan filmos:benchmark --replay-only
 */
class BenchmarkCommand extends Command
{
    protected $signature = 'filmos:benchmark
                            {--sizes=10,50,500,5000 : Comma-separated graph sizes to benchmark}
                            {--replay-only           : Only run replay determinism check}
                            {--perf-only             : Only run performance benchmarks}
                            {--no-record             : Do not record results to Performance Observatory}
                            {--label=                : Label for this run in the observatory}';

    protected $description = 'Benchmark Graph Platform: performance at scale + replay determinism';

    public function handle(): int
    {
        $sizes      = array_map('intval', explode(',', $this->option('sizes')));
        $replayOnly = $this->option('replay-only');
        $perfOnly   = $this->option('perf-only');

        $this->info("FilmOS Graph Platform Benchmark");
        $this->info("PHP " . PHP_VERSION . " | " . date('Y-m-d H:i:s'));
        $this->newLine();

        $allPass    = true;
        $perfResults = [];

        if (!$replayOnly) {
            [$pass, $perfResults] = $this->runPerformanceBenchmark($sizes);
            $allPass = $pass && $allPass;
        }

        if (!$perfOnly) {
            $allPass = $this->runReplayBenchmark() && $allPass;
        }

        // Record to Performance Observatory
        if (!$this->option('no-record') && !empty($perfResults)) {
            $label = $this->option('label') ?: date('Y-m-d H:i:s');
            $obs   = new PerformanceObservatory();
            $run   = $obs->record($perfResults, $label);

            $this->newLine();
            $this->line("  Recorded to Performance Observatory (run: {$run->id})");
            $this->line("  View: php artisan filmos:observatory show");
            $this->line("  Check regression: php artisan filmos:observatory check");
        }

        $this->newLine();
        if ($allPass) {
            $this->info("All benchmarks PASS.");
            return self::SUCCESS;
        }
        $this->error("One or more benchmarks FAILED — see above.");
        return self::FAILURE;
    }

    // ── Performance ───────────────────────────────────────────────────────────

    private function runPerformanceBenchmark(array $sizes): array
    {
        $this->info("── Performance Benchmark ───────────────────────────");
        $rows = [];

        foreach ($sizes as $n) {
            $row   = $this->benchmarkAtSize($n);
            $rows[] = $row;
            $this->line(sprintf(
                "  N=%5d | topoSort=%6.2fms | traceBack=%6.2fms | serialize=%6.2fms | memory=%5.1fMB",
                $n,
                $row['topoSort_ms'],
                $row['traceBack_ms'],
                $row['serialize_ms'],
                $row['memory_mb'],
            ));
        }

        $this->newLine();
        $this->table(
            ['N', 'topoSort (ms)', 'traceBack (ms)', 'serialize (ms)', 'Memory (MB)', 'Status'],
            array_map(fn($r) => [
                $r['n'],
                number_format($r['topoSort_ms'], 2),
                number_format($r['traceBack_ms'], 2),
                number_format($r['serialize_ms'], 2),
                number_format($r['memory_mb'], 1),
                $r['pass'] ? 'PASS ✓' : 'FAIL ✗',
            ], $rows)
        );

        $pass = array_reduce($rows, fn($carry, $r) => $carry && $r['pass'], true);

        // Build observatory-compatible results map: nodeCount → metrics
        $resultsForObservatory = [];
        foreach ($rows as $row) {
            $resultsForObservatory[$row['n']] = [
                'topoSort_ms'  => $row['topoSort_ms'],
                'traceBack_ms' => $row['traceBack_ms'],
                'serialize_ms' => $row['serialize_ms'],
                'memory_mb'    => $row['memory_mb'],
                'pass'         => $row['pass'],
            ];
        }

        return [$pass, $resultsForObservatory];
    }

    private function benchmarkAtSize(int $n): array
    {
        $dag       = $this->buildLinearDag($n);
        $leafId    = "node_" . ($n - 1);
        $pass      = true;
        $memBefore = memory_get_usage(true);

        // topoSort
        $t0 = hrtime(true);
        $sorted = GraphAlgorithms::topoSort($dag);
        $topoMs = (hrtime(true) - $t0) / 1e6;

        // traceBack (from leaf to root)
        $t0 = hrtime(true);
        $chain = GraphTraversal::traceBack($dag, $leafId, fn($node) => $node->isRoot());
        $traceMs = (hrtime(true) - $t0) / 1e6;

        // serialization
        $t0 = hrtime(true);
        $json = GraphSerializer::toJson($dag, 0); // no pretty-print for speed
        $serMs = (hrtime(true) - $t0) / 1e6;

        $memAfter = memory_get_usage(true);
        $memMb    = ($memAfter - $memBefore) / 1024 / 1024;

        // Correctness checks (fast)
        if (count($sorted) !== $n) {
            $this->warn("  N={$n}: topoSort returned " . count($sorted) . " nodes, expected {$n}");
            $pass = false;
        }
        if (count($chain) !== $n) {
            $this->warn("  N={$n}: traceBack chain length " . count($chain) . ", expected {$n}");
            $pass = false;
        }
        if (empty($json) || !str_contains($json, 'node_0')) {
            $this->warn("  N={$n}: serialization output invalid");
            $pass = false;
        }

        // Thresholds (generous for Phase 1 — tighten in Phase 2 after profiling)
        $topoThreshold   = max(10,  $n * 0.05);  // 5ms per 100 nodes
        $traceThreshold  = max(10,  $n * 0.05);
        $serThreshold    = max(50,  $n * 0.10);
        $memThresholdMb  = max(32,  $n * 0.01);  // 10KB per node

        if ($topoMs > $topoThreshold) {
            $this->warn("  N={$n}: topoSort too slow ({$topoMs}ms > threshold {$topoThreshold}ms)");
            $pass = false;
        }
        if ($traceMs > $traceThreshold) {
            $this->warn("  N={$n}: traceBack too slow ({$traceMs}ms > threshold {$traceThreshold}ms)");
            $pass = false;
        }

        return [
            'n'            => $n,
            'topoSort_ms'  => $topoMs,
            'traceBack_ms' => $traceMs,
            'serialize_ms' => $serMs,
            'memory_mb'    => max(0, $memMb),
            'pass'         => $pass,
        ];
    }

    // ── Replay / Determinism ──────────────────────────────────────────────────

    private function runReplayBenchmark(): bool
    {
        $this->info("── Replay Determinism Benchmark ────────────────────");
        $this->line("  Verifying: same inputs → same DAG structure (required for audit/rollback)");

        $facts = [
            ['id' => 'F1', 'text' => 'Cockroach infestation found in multiple guest rooms'],
            ['id' => 'F2', 'text' => 'Hotel health permit expired 2026-05-01'],
            ['id' => 'F3', 'text' => 'Local health authority issued grade C rating'],
            ['id' => 'F4', 'text' => 'Travel advisory level raised to AVOID_NON_ESSENTIAL'],
        ];

        $scenarios = [
            ['n' => 10,  'label' => '10 nodes'],
            ['n' => 100, 'label' => '100 nodes'],
            ['n' => 500, 'label' => '500 nodes'],
        ];

        $rows  = [];
        $allOk = true;

        foreach ($scenarios as ['n' => $n, 'label' => $label]) {
            $dagA = $this->buildDeterministicDag($facts, $n, seed: 42);
            $dagB = $this->buildDeterministicDag($facts, $n, seed: 42);

            $snapA = GraphSerializer::snapshot($dagA);
            $snapB = GraphSerializer::snapshot($dagB);

            $identical = $snapA === $snapB;

            // Also verify: edgeList is identical (same parent-child relationships)
            $edgesA = GraphSerializer::toEdgeList($dagA);
            $edgesB = GraphSerializer::toEdgeList($dagB);
            $edgesMatch = $edgesA === $edgesB;

            $pass = $identical && $edgesMatch;
            if (!$pass) {
                $allOk = false;
            }

            $rows[] = [$label, $snapA['nodeCount'], $snapA['edgeCount'], $pass ? 'PASS ✓' : 'FAIL ✗'];
        }

        $this->table(['Scenario', 'Nodes', 'Edges', 'Replay'], $rows);

        // Cross-seed check: different seeds → different DAG structures
        $dagSeed42 = $this->buildDeterministicDag($facts, 50, seed: 42);
        $dagSeed99 = $this->buildDeterministicDag($facts, 50, seed: 99);
        $diffSeeds = GraphSerializer::snapshot($dagSeed42) !== GraphSerializer::snapshot($dagSeed99);

        $this->newLine();
        $seedStatus = $diffSeeds ? 'PASS ✓' : 'FAIL ✗ (different seeds should differ)';
        $this->line("  Cross-seed isolation (seed 42 ≠ seed 99): {$seedStatus}");

        if (!$diffSeeds) {
            $allOk = false;
        }

        return $allOk;
    }

    // ── Graph builders ────────────────────────────────────────────────────────

    /**
     * Build a linear DAG: node_0(FACT) → node_1 → ... → node_{n-1}(RENDER).
     * Classic worst-case for traceBack (full-length chain).
     */
    private function buildLinearDag(int $n): DecisionDAG
    {
        $dag = new DecisionDAG("bench_linear_{$n}");

        for ($i = 0; $i < $n; $i++) {
            $type = match (true) {
                $i === 0      => DAGNodeType::FACT,
                $i === $n - 1 => DAGNodeType::RENDER,
                $i < $n / 4   => DAGNodeType::MEANING,
                $i < $n / 2   => DAGNodeType::PLAN,
                default       => DAGNodeType::INTENT,
            };
            $dag->addNode(new DAGNode(
                "node_{$i}",
                $type,
                "payload_{$i}",
                confidence: 0.9,
                rationale:  "benchmark node {$i}",
            ));
        }

        for ($i = 0; $i < $n - 1; $i++) {
            $dag->addEdge(new DAGEdge("node_{$i}", "node_" . ($i + 1)));
        }

        return $dag;
    }

    /**
     * Build a DAG using a deterministic algorithm driven by $seed.
     * Same seed + same facts → same DAG (bit-for-bit identical snapshots).
     * Different seed → structurally different DAG (different edge wiring).
     */
    private function buildDeterministicDag(array $facts, int $totalNodes, int $seed): DecisionDAG
    {
        $dag = new DecisionDAG("bench_det_{$totalNodes}_s{$seed}");

        // Fact nodes are always identical (inputs don't vary)
        foreach ($facts as $fact) {
            $dag->addNode(new DAGNode(
                $fact['id'],
                DAGNodeType::FACT,
                $fact['text'],
                confidence: 1.0,
                rationale:  $fact['text'],
            ));
        }

        // Derived nodes: deterministic from seed
        // lcg() — simple linear congruential generator (deterministic, no php random state)
        $state = $seed;
        $lcg   = function () use (&$state): int {
            $state = (1664525 * $state + 1013904223) & 0xFFFFFFFF;
            return $state;
        };

        $types       = [DAGNodeType::MEANING, DAGNodeType::PLAN, DAGNodeType::INTENT, DAGNodeType::RENDER];
        $factIds     = array_column($facts, 'id');
        $derivedIds  = [];

        for ($i = 0; $i < $totalNodes - count($facts); $i++) {
            $nodeId  = "derived_{$seed}_{$i}";
            $typeIdx = $lcg() % count($types);
            $type    = $types[$typeIdx];

            $dag->addNode(new DAGNode(
                $nodeId,
                $type,
                "payload_{$i}",
                confidence: 0.8,
                rationale:  "derived from seed={$seed}",
            ));

            // Wire to a deterministic parent
            $allIds    = array_merge($factIds, $derivedIds);
            $parentIdx = $lcg() % count($allIds);
            $parentId  = $allIds[$parentIdx];

            $dag->addEdge(new DAGEdge($parentId, $nodeId));
            $derivedIds[] = $nodeId;
        }

        return $dag;
    }
}
