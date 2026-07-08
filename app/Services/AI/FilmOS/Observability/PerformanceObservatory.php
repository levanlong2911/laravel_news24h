<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Observability;

/**
 * Thu thập và lưu lịch sử benchmark để phát hiện regression.
 *
 * Giống regression benchmark của compiler/database lớn:
 *   - Mỗi lần chạy filmos:benchmark → ghi kết quả vào file JSON
 *   - Sau nhiều lần chạy → so sánh với baseline để phát hiện degradation
 *   - CI có thể fail nếu topoSort tăng từ 2ms → 20ms
 *
 * Storage: storage/app/filmos/observatory.json
 * Format: append-only log của các BenchmarkRun, giữ lại N runs gần nhất
 */
final class PerformanceObservatory
{
    private const MAX_RUNS   = 100;  // giữ lại 100 runs gần nhất
    private const STORE_PATH = 'filmos/observatory.json';

    public function __construct(
        private readonly string $storagePath = '',
    ) {}

    // ── Record ────────────────────────────────────────────────────────────────

    /**
     * Ghi lại một benchmark run.
     * @param  array<int, array{topoSort_ms: float, traceBack_ms: float, serialize_ms: float, memory_mb: float}> $results
     */
    public function record(array $results, ?string $label = null): BenchmarkRun
    {
        $run = new BenchmarkRun(
            id:        uniqid('run_', more_entropy: true),
            timestamp: time(),
            label:     $label ?? date('Y-m-d H:i:s'),
            gitCommit: $this->currentCommit(),
            results:   $results,
        );

        $history   = $this->load();
        $history[] = $run->toArray();

        // Keep only last N runs
        if (count($history) > self::MAX_RUNS) {
            $history = array_slice($history, -self::MAX_RUNS);
        }

        $this->persist($history);
        return $run;
    }

    // ── Regression detection ──────────────────────────────────────────────────

    /**
     * Kiểm tra regression so với baseline (median của M runs gần nhất).
     * @return RegressionReport[]
     */
    public function checkRegression(array $currentResults, int $baselineRuns = 10, float $threshold = 1.20): array
    {
        $history  = $this->load();
        $recent   = array_slice($history, -$baselineRuns);

        if (count($recent) < 3) {
            return []; // không đủ dữ liệu để so sánh
        }

        $regressions = [];

        foreach ($currentResults as $n => $current) {
            $metrics = ['topoSort_ms', 'traceBack_ms', 'serialize_ms'];
            foreach ($metrics as $metric) {
                $historicalValues = array_filter(array_map(
                    fn($run) => $run['results'][$n][$metric] ?? null,
                    $recent,
                ));

                if (empty($historicalValues)) {
                    continue;
                }

                $baseline = $this->median(array_values($historicalValues));
                $current_val = $current[$metric] ?? 0.0;

                if ($baseline > 0 && $current_val > $baseline * $threshold) {
                    $regressions[] = new RegressionReport(
                        metric:      "N={$n} {$metric}",
                        current:     $current_val,
                        baseline:    $baseline,
                        ratio:       $current_val / $baseline,
                        threshold:   $threshold,
                    );
                }
            }
        }

        return $regressions;
    }

    // ── Query ─────────────────────────────────────────────────────────────────

    /**
     * @return BenchmarkRun[]
     */
    public function lastN(int $n = 10): array
    {
        $history = $this->load();
        $recent  = array_slice($history, -$n);
        return array_map(fn($r) => BenchmarkRun::fromArray($r), $recent);
    }

    public function hasHistory(): bool
    {
        return count($this->load()) > 0;
    }

    /**
     * Trend: hiển thị một metric theo thời gian.
     * @return array<array{timestamp: int, value: float}>
     */
    public function trend(int $nodeSize, string $metric, int $lastN = 20): array
    {
        $history = array_slice($this->load(), -$lastN);
        $points  = [];
        foreach ($history as $run) {
            $val = $run['results'][$nodeSize][$metric] ?? null;
            if ($val !== null) {
                $points[] = ['timestamp' => $run['timestamp'], 'value' => $val];
            }
        }
        return $points;
    }

    // ── Storage ───────────────────────────────────────────────────────────────

    private function storePath(): string
    {
        if ($this->storagePath !== '') {
            return $this->storagePath;
        }
        return storage_path('app/' . self::STORE_PATH);
    }

    private function load(): array
    {
        $path = $this->storePath();
        if (!file_exists($path)) {
            return [];
        }
        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private function persist(array $history): void
    {
        $path = $this->storePath();
        $dir  = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, json_encode($history, JSON_PRETTY_PRINT));
    }

    private function currentCommit(): string
    {
        $output = shell_exec('git rev-parse --short HEAD 2>/dev/null');
        return $output ? trim($output) : 'unknown';
    }

    private function median(array $values): float
    {
        sort($values);
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        $mid = (int) floor($n / 2);
        return $n % 2 === 0
            ? ($values[$mid - 1] + $values[$mid]) / 2.0
            : (float) $values[$mid];
    }
}

// ── Value Objects ─────────────────────────────────────────────────────────────

final class BenchmarkRun
{
    public function __construct(
        public readonly string $id,
        public readonly int    $timestamp,
        public readonly string $label,
        public readonly string $gitCommit,
        /** @var array<int, array{topoSort_ms: float, traceBack_ms: float, serialize_ms: float, memory_mb: float}> */
        public readonly array  $results,
    ) {}

    public function toArray(): array
    {
        return [
            'id'        => $this->id,
            'timestamp' => $this->timestamp,
            'label'     => $this->label,
            'gitCommit' => $this->gitCommit,
            'results'   => $this->results,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id:        $data['id'] ?? '',
            timestamp: $data['timestamp'] ?? 0,
            label:     $data['label'] ?? '',
            gitCommit: $data['gitCommit'] ?? 'unknown',
            results:   $data['results'] ?? [],
        );
    }

    public function date(): string
    {
        return date('Y-m-d H:i:s', $this->timestamp);
    }
}

final class RegressionReport
{
    public function __construct(
        public readonly string $metric,
        public readonly float  $current,
        public readonly float  $baseline,
        public readonly float  $ratio,
        public readonly float  $threshold,
    ) {}

    public function isRegression(): bool
    {
        return $this->ratio > $this->threshold;
    }

    public function summary(): string
    {
        return sprintf(
            '%s: %.2fms (baseline=%.2fms, ratio=%.2fx, threshold=%.2fx)',
            $this->metric,
            $this->current,
            $this->baseline,
            $this->ratio,
            $this->threshold,
        );
    }
}
