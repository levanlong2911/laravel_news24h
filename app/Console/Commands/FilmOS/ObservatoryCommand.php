<?php

declare(strict_types=1);

namespace App\Console\Commands\FilmOS;

use App\Services\AI\FilmOS\Observability\PerformanceObservatory;
use App\Services\AI\FilmOS\Observability\RegressionReport;
use Illuminate\Console\Command;

/**
 * Xem lịch sử benchmark và phát hiện regression.
 *
 * Usage:
 *   php artisan filmos:observatory show          — xem 10 runs gần nhất
 *   php artisan filmos:observatory show --n=20   — xem 20 runs
 *   php artisan filmos:observatory trend         — trend của topoSort_ms theo thời gian
 *   php artisan filmos:observatory check         — regression check (dùng trong CI)
 *   php artisan filmos:observatory compare       — so sánh last 2 runs
 */
class ObservatoryCommand extends Command
{
    protected $signature = 'filmos:observatory
                            {action=show       : show | trend | check | compare}
                            {--n=10            : Số runs hiển thị}
                            {--size=5000       : Node size để check trend}
                            {--metric=topoSort_ms : Metric để track}
                            {--threshold=1.20  : Regression threshold (1.20 = 20% slower)}';

    protected $description = 'Performance Observatory — lịch sử benchmark và phát hiện regression';

    public function handle(): int
    {
        $observatory = new PerformanceObservatory();
        $action      = $this->argument('action');

        if (!$observatory->hasHistory()) {
            $this->warn("Chưa có dữ liệu. Chạy 'php artisan filmos:benchmark' trước.");
            return self::SUCCESS;
        }

        return match ($action) {
            'show'    => $this->showHistory($observatory),
            'trend'   => $this->showTrend($observatory),
            'check'   => $this->checkRegression($observatory),
            'compare' => $this->compareRuns($observatory),
            default   => $this->showHistory($observatory),
        };
    }

    private function showHistory(PerformanceObservatory $obs): int
    {
        $n    = (int) $this->option('n');
        $runs = $obs->lastN($n);

        $this->info("FilmOS Performance Observatory — last {$n} runs");
        $this->newLine();

        if (empty($runs)) {
            $this->line("Chưa có dữ liệu.");
            return self::SUCCESS;
        }

        // Show last run in detail
        $last = end($runs);
        $this->info("── Latest run: {$last->label} (commit: {$last->gitCommit}) ─────");
        $sizes = array_keys($last->results);
        $rows  = [];
        foreach ($sizes as $size) {
            $r = $last->results[$size];
            $rows[] = [
                $size,
                number_format($r['topoSort_ms'] ?? 0, 2),
                number_format($r['traceBack_ms'] ?? 0, 2),
                number_format($r['serialize_ms'] ?? 0, 2),
                number_format($r['memory_mb'] ?? 0, 1),
                ($r['pass'] ?? true) ? '✓' : '✗',
            ];
        }
        $this->table(['N', 'topoSort (ms)', 'traceBack (ms)', 'serialize (ms)', 'Memory (MB)', 'Pass'], $rows);

        // History table
        $this->info("── Run history ──────────────────────────────────────");
        $histRows = [];
        foreach ($runs as $run) {
            $largestSize = max(array_keys($run->results));
            $r           = $run->results[$largestSize];
            $histRows[] = [
                $run->date(),
                $run->gitCommit,
                "N={$largestSize}",
                number_format($r['topoSort_ms'] ?? 0, 2),
                number_format($r['traceBack_ms'] ?? 0, 2),
                number_format($r['memory_mb'] ?? 0, 1),
            ];
        }
        $this->table(['Date', 'Commit', 'Size', 'topoSort (ms)', 'traceBack (ms)', 'Memory (MB)'], $histRows);

        return self::SUCCESS;
    }

    private function showTrend(PerformanceObservatory $obs): int
    {
        $size   = (int) $this->option('size');
        $metric = $this->option('metric');
        $n      = (int) $this->option('n');
        $points = $obs->trend($size, $metric, $n);

        $this->info("Trend: {$metric} at N={$size} (last {$n} runs)");
        $this->newLine();

        if (empty($points)) {
            $this->warn("Không có dữ liệu cho size={$size}, metric={$metric}");
            return self::SUCCESS;
        }

        $values = array_column($points, 'value');
        $min    = min($values);
        $max    = max($values);
        $avg    = array_sum($values) / count($values);

        $this->line(sprintf("  min=%.2fms  avg=%.2fms  max=%.2fms", $min, $avg, $max));
        $this->newLine();

        // ASCII sparkline
        $range      = $max - $min;
        $sparkChars = ['▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];
        $spark      = '';
        foreach ($values as $v) {
            $idx   = $range > 0 ? (int) (($v - $min) / $range * 7) : 0;
            $spark .= $sparkChars[min(7, $idx)];
        }

        $this->line("  {$spark}");
        $this->line("  └── oldest ─────────────── newest ──┘");
        $this->newLine();

        // Trend direction
        if (count($values) >= 3) {
            $half    = (int) (count($values) / 2);
            $oldHalf = array_sum(array_slice($values, 0, $half)) / $half;
            $newHalf = array_sum(array_slice($values, -$half)) / $half;
            $change  = ($newHalf - $oldHalf) / $oldHalf * 100;
            $dir     = $change > 5 ? 'SLOWING ↑' : ($change < -5 ? 'FASTER ↓' : 'STABLE →');
            $this->line(sprintf("  Trend: %s (%.1f%% change)", $dir, $change));
        }

        return self::SUCCESS;
    }

    private function checkRegression(PerformanceObservatory $obs): int
    {
        $threshold = (float) $this->option('threshold');
        $runs      = $obs->lastN(1);

        if (empty($runs)) {
            $this->warn("Không có run gần nhất để check. Chạy benchmark trước.");
            return self::FAILURE;
        }

        $lastRun  = end($runs);
        $regressions = $obs->checkRegression($lastRun->results, baselineRuns: 10, threshold: $threshold);

        if (empty($regressions)) {
            $this->info("No regressions detected. All metrics within {$threshold}x of baseline.");
            return self::SUCCESS;
        }

        $this->error("REGRESSION DETECTED:");
        foreach ($regressions as $reg) {
            $this->line("  ✗ " . $reg->summary());
        }

        return self::FAILURE;
    }

    private function compareRuns(PerformanceObservatory $obs): int
    {
        $runs = $obs->lastN(2);

        if (count($runs) < 2) {
            $this->warn("Cần ít nhất 2 runs để so sánh.");
            return self::FAILURE;
        }

        [$older, $newer] = $runs;

        $this->info("Comparing:");
        $this->line("  Older: {$older->label} ({$older->gitCommit})");
        $this->line("  Newer: {$newer->label} ({$newer->gitCommit})");
        $this->newLine();

        $rows = [];
        foreach ($older->results as $size => $oldMetrics) {
            $newMetrics = $newer->results[$size] ?? [];
            foreach (['topoSort_ms', 'traceBack_ms', 'serialize_ms'] as $m) {
                $old = $oldMetrics[$m] ?? 0;
                $new = $newMetrics[$m] ?? 0;
                $delta = $old > 0 ? (($new - $old) / $old * 100) : 0;
                $icon  = $delta > 10 ? '↑ SLOWER' : ($delta < -10 ? '↓ FASTER' : '→ STABLE');
                $rows[] = ["N={$size}", $m, number_format($old, 2), number_format($new, 2), sprintf('%+.1f%% %s', $delta, $icon)];
            }
        }

        $this->table(['Size', 'Metric', 'Older (ms)', 'Newer (ms)', 'Delta'], $rows);
        return self::SUCCESS;
    }
}
