<?php

namespace App\Console\Commands;

use App\Services\AI\AFOS\Backend\BackendCapabilityRegistry;
use App\Services\AI\AFOS\Backend\KlingCapability;
use App\Services\AI\AFOS\Benchmark\BaselineStore;
use App\Services\AI\AFOS\Benchmark\QAExpectation;
use App\Services\AI\AFOS\Observability\TraceCollector;
use App\Services\AI\AFOS\Benchmark\BenchmarkStageStats;
use App\Services\AI\AFOS\Passes\AfosPassManager;
use App\Services\AI\AFOS\Planning\ShotGoalIRAdapter;
use App\Services\AI\ScenePlanner\ScenePlanner;
use Illuminate\Console\Command;
use Symfony\Component\Yaml\Yaml;

/**
 * AfosBenchmark — AFOS IR compiler benchmark and regression harness.
 *
 * Shot presets are loaded from YAML domain packs:
 *   resources/afos/domains/{domain}/dsl_examples.yaml
 *   resources/afos/domains/{domain}/expected_outputs.yaml
 *
 * Usage:
 *   php artisan afos:benchmark --shots=10 --domain=luxury_villa
 *   php artisan afos:benchmark --domain=all --shots=4
 *   php artisan afos:benchmark --domain=all --shots=4 --lock-baseline=v1
 *   php artisan afos:benchmark --domain=all --compare-baseline=v1
 *   php artisan afos:benchmark --domain=luxury_villa --compare=20260704_151205
 *
 * Output:
 *   storage/app/afos-benchmark/{run_id}/
 *   ├── {shot_id}/afos_prompt.txt
 *   └── summary.json / cross_domain_summary.json
 *
 * Baselines:
 *   resources/afos/baselines/{name}/  (immutable, version-controlled)
 */
class AfosBenchmark extends Command
{
    protected $signature = 'afos:benchmark
        {--shots=10 : Number of shots to benchmark}
        {--domain=luxury_villa : Domain preset (luxury_villa|automotive|superyacht|architecture|sports|all)}
        {--compare= : Previous run_id to regression-compare PromptIR hashes against}
        {--lock-baseline= : Lock results as immutable named baseline (e.g. v1)}
        {--compare-baseline= : Compare results against a locked baseline (e.g. v1)}
        {--output= : Output directory (default: storage/app/afos-benchmark/{timestamp})}
        {--stage-timing : Show per-stage compile time breakdown after each shot}';

    protected $description = 'AFOS compiler benchmark — loads domain packs from YAML, optional regression diff and baseline management';

    public function handle(): int
    {
        BackendCapabilityRegistry::register(KlingCapability::make());

        $shots           = (int) $this->option('shots');
        $domain          = (string) $this->option('domain');
        $compare         = $this->option('compare');
        $lockBaseline    = $this->option('lock-baseline');
        $compareBaseline = $this->option('compare-baseline');
        $runId           = now()->format('Ymd_His');
        $outDir          = $this->option('output') ?: storage_path("app/afos-benchmark/{$runId}");

        @mkdir($outDir, 0755, true);

        if ($domain === 'all') {
            return $this->runAllDomains($shots, $runId, $outDir, $compare, $lockBaseline, $compareBaseline);
        }

        $this->info("AFOS Benchmark — {$shots} shots, domain: {$domain}");
        $this->info("Output: {$outDir}");
        $this->newLine();

        $dsls        = $this->loadDslSuite($domain, $shots);
        $groundTruth = $this->loadGroundTruth($domain);
        $results     = [];
        $stageStats  = new BenchmarkStageStats();
        $passManager = AfosPassManager::defaults();

        foreach ($dsls as $i => $dsl) {
            $shotId  = "bench-{$runId}-sh" . sprintf('%03d', $i + 1);
            $shotDir = "{$outDir}/{$shotId}";
            @mkdir($shotDir, 0755, true);

            $planningResult = app(ScenePlanner::class)->plan($dsl);
            $trace          = new TraceCollector($shotId);
            $shotGoalIR     = ShotGoalIRAdapter::toShotGoalIR($planningResult);
            $director       = ShotGoalIRAdapter::toDirectorProfile($planningResult);
            $dp             = ShotGoalIRAdapter::toCinematographyProfile($planningResult);
            $intent         = ShotGoalIRAdapter::toIntent($planningResult);

            $trace->record('shot_goal_ir',     $shotGoalIR->toArray());
            $trace->record('intent',           $intent->toArray());
            $trace->record('director_profile', $director->toArray());

            $startMs    = microtime(true) * 1000;
            $snapshot   = $passManager->compileWithSnapshot($shotGoalIR, $director, $dp, $intent, $trace);
            $durationMs = round(microtime(true) * 1000 - $startMs, 2);

            $trace->flush();
            file_put_contents("{$shotDir}/afos_prompt.txt", $snapshot->artifacts->compiledPrompt);

            $capability    = BackendCapabilityRegistry::get('kling');
            $estimatedCost = $capability ? $capability->costPerSecondUsd * ($dsl['dur'] ?? 5.0) : 0.0;
            $sceneTitle    = $dsl['scene_title'] ?? '';
            $expected      = $groundTruth[$sceneTitle] ?? null;
            $entityId      = $snapshot->semantic->entityId;

            $results[] = [
                'shot_id'         => $shotId,
                'scene_title'     => $sceneTitle,
                'goal_target'     => $entityId,
                'expected_entity' => $expected['expected_entity'] ?? null,
                'entity_match'    => $expected ? ($entityId === $expected['expected_entity']) : null,
                'afos_prompt_len' => strlen($snapshot->artifacts->compiledPrompt),
                'compile_ms'      => $durationMs,
                'stage_ms'        => $this->profilesToArray($snapshot->profiles),
                'estimated_cost'  => $estimatedCost,
                'semantic_hash'   => $snapshot->semanticHash,
                'snapshot'        => $snapshot->toArray(),
                'qa_score'        => null,
                'ir_fidelity'     => null,
            ];

            $stageStats->recordAll($snapshot->profiles);

            $entityIcon = ($expected && $entityId === $expected['expected_entity']) ? '✓' : '·';
            $this->line(sprintf(
                '  [%03d/%03d] %-26s → %s entity=%-20s %d chars %.1fms',
                $i + 1, $shots,
                '"' . substr($sceneTitle, 0, 24) . '"',
                $entityIcon,
                $entityId,
                strlen($snapshot->artifacts->compiledPrompt),
                $durationMs
            ));

            if ($this->option('stage-timing')) {
                $this->line('             ' . $this->formatStageProfiles($snapshot->profiles));
            }
        }

        $entityMatches = count(array_filter($results, fn($r) => $r['entity_match'] === true));
        $entityTotal   = count(array_filter($results, fn($r) => $r['entity_match'] !== null));

        $summary = [
            'run_id'              => $runId,
            'domain'              => $domain,
            'shots_benchmarked'   => count($results),
            'generated_at'        => now()->toIso8601String(),
            'pipeline'            => 'AFOS-v1.0',
            'domain_pack_version' => $this->getDomainPackVersion($domain),
            'avg_compile_ms'      => round(array_sum(array_column($results, 'compile_ms')) / max(1, count($results)), 2),
            'avg_stage_ms'        => $this->avgStageMsAcrossResults($results),
            'avg_prompt_len'      => round(array_sum(array_column($results, 'afos_prompt_len')) / max(1, count($results))),
            'total_cost_est'      => round(array_sum(array_column($results, 'estimated_cost')), 4),
            'entity_match_rate'   => $entityTotal > 0 ? round($entityMatches / $entityTotal, 3) : null,
            'baseline_locked'     => false,
            'stage_percentiles'   => $stageStats->describeAll(),
            'results'             => $results,
        ];

        file_put_contents("{$outDir}/summary.json", json_encode($summary, JSON_PRETTY_PRINT));
        file_put_contents("{$outDir}/timeline.json", json_encode($this->buildTimeline($runId, $domain, $results), JSON_PRETTY_PRINT));
        file_put_contents("{$outDir}/chrome_trace.json", json_encode($this->buildChromeTrace($results), JSON_PRETTY_PRINT));

        $this->newLine();
        if ($entityTotal > 0) {
            $rate = round($entityMatches / $entityTotal * 100);
            $this->info("Entity match rate: {$entityMatches}/{$entityTotal} ({$rate}%)");
        }
        $this->info("Benchmark complete: {$outDir}/summary.json");
        $this->line("  Avg compile: {$summary['avg_compile_ms']}ms");
        $this->line("  Avg prompt length: {$summary['avg_prompt_len']} chars");
        $this->line("  Total est. cost: \${$summary['total_cost_est']}");

        if ($compare) {
            $this->newLine();
            $this->runRegressionDiff($compare, $results, $domain);
        }

        return self::SUCCESS;
    }

    private function runAllDomains(
        int     $shotsPerDomain,
        string  $runId,
        string  $outDir,
        ?string $compare,
        ?string $lockBaseline,
        ?string $compareBaseline,
    ): int {
        $domains     = ['luxury_villa', 'automotive', 'superyacht', 'architecture', 'sports'];
        $passManager = AfosPassManager::defaults();
        $domainStats = [];

        // For BaselineStore: domain → results[]
        $domainResults = [];

        foreach ($domains as $domain) {
            $this->info("── Domain: {$domain} ──────────────────────────────");
            $dsls        = $this->loadDslSuite($domain, $shotsPerDomain);
            $groundTruth = $this->loadGroundTruth($domain);
            $results     = [];

            // Show QA metric count if available
            try {
                $expectations = QAExpectation::forDomain($domain);
                $metricTotal  = array_sum(array_map(fn($e) => count($e->metrics), $expectations));
                $this->line("  QA metrics: {$metricTotal} across " . count($expectations) . " scenes");
            } catch (\Throwable) {
                // domain has no expectations yet
            }

            foreach ($dsls as $i => $dsl) {
                $shotId  = "bench-{$runId}-{$domain}-sh" . sprintf('%03d', $i + 1);
                $shotDir = "{$outDir}/{$shotId}";
                @mkdir($shotDir, 0755, true);

                $planningResult = app(ScenePlanner::class)->plan($dsl);
                $trace          = new TraceCollector($shotId);
                $shotGoalIR     = ShotGoalIRAdapter::toShotGoalIR($planningResult);
                $director       = ShotGoalIRAdapter::toDirectorProfile($planningResult);
                $dp             = ShotGoalIRAdapter::toCinematographyProfile($planningResult);
                $intent         = ShotGoalIRAdapter::toIntent($planningResult);

                $trace->record('shot_goal_ir',     $shotGoalIR->toArray());
                $trace->record('intent',           $intent->toArray());
                $trace->record('director_profile', $director->toArray());

                $startMs    = microtime(true) * 1000;
                $snapshot   = $passManager->compileWithSnapshot($shotGoalIR, $director, $dp, $intent, $trace);
                $durationMs = round(microtime(true) * 1000 - $startMs, 2);

                $trace->flush();
                file_put_contents("{$shotDir}/afos_prompt.txt", $snapshot->artifacts->compiledPrompt);

                $capability    = BackendCapabilityRegistry::get('kling');
                $estimatedCost = $capability ? $capability->costPerSecondUsd * ($dsl['dur'] ?? 5.0) : 0.0;
                $sceneTitle    = $dsl['scene_title'] ?? '';
                $expected      = $groundTruth[$sceneTitle] ?? null;
                $entityId      = $snapshot->semantic->entityId;

                $results[] = [
                    'shot_id'         => $shotId,
                    'scene_title'     => $sceneTitle,
                    'goal_target'     => $entityId,
                    'expected_entity' => $expected['expected_entity'] ?? null,
                    'entity_match'    => $expected ? ($entityId === $expected['expected_entity']) : null,
                    'afos_prompt_len' => strlen($snapshot->artifacts->compiledPrompt),
                    'compile_ms'      => $durationMs,
                    'stage_ms'        => $this->profilesToArray($snapshot->profiles),
                    'estimated_cost'  => $estimatedCost,
                    'semantic_hash'   => $snapshot->semanticHash,
                    'snapshot'        => $snapshot->toArray(),
                    'qa_score'        => null,
                    'ir_fidelity'     => null,
                ];

                $entityIcon = ($expected && $entityId === $expected['expected_entity']) ? '✓' : '·';
                $this->line(sprintf(
                    '  [%02d] %s %-26s → entity=%-18s %d chars',
                    $i + 1, $entityIcon,
                    '"' . substr($sceneTitle, 0, 24) . '"',
                    $entityId,
                    strlen($snapshot->artifacts->compiledPrompt)
                ));

                if ($this->option('stage-timing')) {
                    $this->line('       ' . $this->formatStageProfiles($snapshot->profiles));
                }
            }

            $entityMatches = count(array_filter($results, fn($r) => $r['entity_match'] === true));
            $entityTotal   = count(array_filter($results, fn($r) => $r['entity_match'] !== null));

            $domainStats[$domain] = [
                'shots'             => count($results),
                'avg_compile_ms'    => round(array_sum(array_column($results, 'compile_ms')) / max(1, count($results)), 2),
                'avg_prompt_len'    => round(array_sum(array_column($results, 'afos_prompt_len')) / max(1, count($results))),
                'total_cost_est'    => round(array_sum(array_column($results, 'estimated_cost')), 4),
                'entity_match_rate' => $entityTotal > 0 ? round($entityMatches / $entityTotal, 3) : null,
                'entity_ids'        => array_column($results, 'goal_target'),
                'results'           => $results,
            ];

            $domainResults[$domain] = $results;

            $this->newLine();
        }

        $summary = [
            'run_id'           => $runId,
            'domains_run'      => $domains,
            'shots_per_domain' => $shotsPerDomain,
            'generated_at'     => now()->toIso8601String(),
            'pipeline'         => 'AFOS-v1.0',
            'baseline_locked'  => $lockBaseline ?? false,
            'domain_stats'     => $domainStats,
            'total_shots'      => array_sum(array_map(fn($d) => $d['shots'], $domainStats)),
            'total_cost_est'   => round(array_sum(array_map(fn($d) => $d['total_cost_est'], $domainStats)), 4),
        ];

        file_put_contents("{$outDir}/cross_domain_summary.json", json_encode($summary, JSON_PRETTY_PRINT));

        $this->info('Cross-domain benchmark complete');
        $this->newLine();
        $this->line(sprintf('  %-14s  %6s  %8s  %8s  %10s  %s', 'Domain', 'Shots', 'Avg ms', 'Avg len', 'Entity%', 'Est. cost'));
        $this->line('  ' . str_repeat('─', 68));
        foreach ($domainStats as $d => $s) {
            $pct = $s['entity_match_rate'] !== null ? round($s['entity_match_rate'] * 100) . '%' : 'n/a';
            $this->line(sprintf('  %-14s  %6d  %8.1f  %8d  %10s  $%.4f', $d, $s['shots'], $s['avg_compile_ms'], $s['avg_prompt_len'], $pct, $s['total_cost_est']));
        }
        $this->newLine();
        $this->info("Summary: {$outDir}/cross_domain_summary.json");

        if ($compare) {
            $this->newLine();
            foreach ($domains as $domain) {
                $this->runRegressionDiff($compare, $domainStats[$domain]['results'], $domain);
            }
        }

        if ($lockBaseline) {
            $this->newLine();
            $this->lockBaseline($lockBaseline, $domainResults);
        }

        if ($compareBaseline) {
            $this->newLine();
            $this->compareBaseline($compareBaseline, $domainResults);
        }

        return self::SUCCESS;
    }

    private function lockBaseline(string $name, array $domainResults): void
    {
        $store = new BaselineStore();

        if ($store->exists($name)) {
            $this->error("Baseline '{$name}' already exists and is immutable. Use a new name (e.g. v2).");
            return;
        }

        try {
            $store->lock($name, $domainResults);
            $total = array_sum(array_map('count', $domainResults));
            $this->info("Baseline '{$name}' locked — {$total} scenes across " . count($domainResults) . " domains");
            $this->line("  Path: resources/afos/baselines/{$name}/");
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
        }
    }

    private function compareBaseline(string $name, array $domainResults): void
    {
        $store = new BaselineStore();

        try {
            $diff = $store->compare($name, $domainResults);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return;
        }

        if (!$diff['schema_compatible']) {
            $this->warn("Schema mismatch: baseline={$diff['baseline_schema']}, current={$diff['current_schema']} — hashes are incompatible");
        }

        $this->info("── Baseline compare: '{$name}' ─────────────────────────");

        $totalChanged   = 0;
        $totalUnchanged = 0;

        foreach ($diff['diffs'] as $domain => $d) {
            if ($d['status'] === 'no_baseline') {
                $this->line("  {$domain}: no baseline data");
                continue;
            }

            $changed   = count($d['changes']);
            $unchanged = $d['unchanged'];
            $totalChanged   += $changed;
            $totalUnchanged += $unchanged;

            $icon = $changed === 0 ? '✓' : '!';
            $this->line("  [{$icon}] {$domain}: {$unchanged} unchanged, {$changed} changed");

            foreach ($d['changes'] as $change) {
                if ($change['type'] === 'new') {
                    $this->line("      [NEW]     {$change['scene']}");
                } else {
                    $this->line("      [CHANGED] {$change['scene']}  {$change['base_hash']}… → {$change['curr_hash']}…");
                }
            }
        }

        $total = $totalChanged + $totalUnchanged;
        $this->newLine();

        if ($totalChanged === 0) {
            $this->info("  No regressions — all {$total} scenes match baseline '{$name}'");
        } elseif ($total > 0 && ($totalChanged / $total) > 0.05) {
            $this->error("  REGRESSION GATE: {$totalChanged}/{$total} scenes changed (>5%) — review before locking next baseline");
        } else {
            $this->warn("  {$totalChanged}/{$total} scenes changed — within 5% threshold, verify intentional");
        }
    }

    /**
     * Diff PromptIR hashes against a previous run to detect compiler regressions.
     */
    private function runRegressionDiff(string $compareRunId, array $currentResults, string $domain): void
    {
        $baseDir  = storage_path("app/afos-benchmark/{$compareRunId}");
        $basePath = file_exists("{$baseDir}/cross_domain_summary.json")
            ? "{$baseDir}/cross_domain_summary.json"
            : "{$baseDir}/summary.json";

        if (!file_exists($basePath)) {
            $this->warn("Regression: cannot find baseline run {$compareRunId} at {$basePath}");
            return;
        }

        $baseline    = json_decode(file_get_contents($basePath), true);
        $baseResults = [];

        if (isset($baseline['domain_stats'][$domain])) {
            $baseResults = $baseline['domain_stats'][$domain]['results'] ?? [];
        } elseif (isset($baseline['results'])) {
            $baseResults = $baseline['results'];
        }

        if (empty($baseResults)) {
            $this->warn("Regression [{$domain}]: no baseline results found in {$compareRunId}");
            return;
        }

        $baseByScene = [];
        foreach ($baseResults as $r) {
            $baseByScene[$r['scene_title']] = $r;
        }

        $changed   = 0;
        $unchanged = 0;
        $new       = 0;

        $this->info("── Regression diff [{$domain}] vs {$compareRunId} ──");

        foreach ($currentResults as $curr) {
            $scene = $curr['scene_title'];
            if (!isset($baseByScene[$scene])) {
                $this->line("  [NEW]     {$scene}");
                $new++;
                continue;
            }

            $baseHash = $baseByScene[$scene]['semantic_hash'] ?? null;
            $currHash = $curr['semantic_hash'] ?? null;

            if ($baseHash === $currHash) {
                $unchanged++;
            } else {
                $this->line("  [CHANGED] {$scene}");
                $this->line("            hash {$baseHash} → {$currHash}");
                $changed++;
            }
        }

        $total = $changed + $unchanged + $new;
        $this->line(sprintf(
            '  Result: %d/%d changed, %d unchanged, %d new',
            $changed, $total, $unchanged, $new
        ));

        if ($changed > 0 && ($changed / max(1, $total)) > 0.05) {
            $this->error("  REGRESSION GATE: {$changed}/{$total} shots changed (>5% threshold)");
        } elseif ($changed > 0) {
            $this->warn("  {$changed} shot(s) changed — within threshold, review intentional");
        } else {
            $this->info("  No regressions detected.");
        }
    }

    /**
     * Render StageProfile[] as a compact single line for console output.
     * "ShotValid:0.02  Tier1:0.18  Tier2:0.08  CamValid:0.01  Tier3:0.34  Backend:0.11"
     * When memory deltas are non-zero: "Tier1:0.18(+4.2KB)"
     *
     * @param \App\Services\AI\AFOS\Passes\Pipeline\StageProfile[] $profiles
     */
    private function formatStageProfiles(array $profiles): string
    {
        $short = [
            'ShotValidationStage'   => 'ShotValid',
            'Tier1Stage'            => 'Tier1',
            'Tier2Stage'            => 'Tier2',
            'CameraValidationStage' => 'CamValid',
            'Tier3Stage'            => 'Tier3',
            'BackendStage'          => 'Backend',
        ];

        return implode('  ', array_map(function ($p) use ($short) {
            $label = $short[$p->stageName] ?? $p->stageName;
            $mem   = $p->memoryDelta > 0 ? sprintf('(+%.1fKB)', $p->memoryDelta / 1024) : '';
            return sprintf('%s:%s%s', $label, number_format($p->durationMs, 2), $mem);
        }, $profiles));
    }

    /**
     * Convert StageProfile[] to a structured array for JSON storage.
     *
     * @param \App\Services\AI\AFOS\Passes\Pipeline\StageProfile[] $profiles
     * @return array<string, array>
     */
    private function profilesToArray(array $profiles): array
    {
        $result = [];
        foreach ($profiles as $p) {
            $result[$p->stageName] = [
                'ms'           => $p->durationMs,
                'memory_delta' => $p->memoryDelta,
                'errors'       => $p->errorCount,
                'warnings'     => $p->warningCount,
                'hints'        => $p->hintCount,
            ];
        }
        return $result;
    }

    /**
     * Build chrome_trace.json in Chrome Trace Event Format (Catapult / Perfetto).
     * Open at chrome://tracing or https://ui.perfetto.dev
     *
     * ts/dur are in microseconds (Chrome Trace convention).
     * Each shot gets its own thread_id so lanes appear separately in the viewer.
     *
     * @param array<int, array<string, mixed>> $results
     */
    private function buildChromeTrace(array $results): array
    {
        $events = [];
        $pid    = 1;  // process id (benchmark run)

        foreach ($results as $tid => $r) {
            // Thread name metadata event
            $events[] = [
                'ph'   => 'M',
                'pid'  => $pid,
                'tid'  => $tid + 1,
                'name' => 'thread_name',
                'args' => ['name' => substr($r['scene_title'], 0, 30)],
            ];

            $offsetUs = 0.0;  // cumulative offset in microseconds

            foreach ($r['stage_ms'] as $stageName => $data) {
                $durationMs = is_array($data) ? $data['ms'] : (float) $data;
                $durationUs = round($durationMs * 1000);
                $memDelta   = is_array($data) ? $data['memory_delta'] : 0;

                $events[] = [
                    'ph'   => 'X',  // Complete event (start + duration)
                    'pid'  => $pid,
                    'tid'  => $tid + 1,
                    'name' => $stageName,
                    'ts'   => (int) round($offsetUs),
                    'dur'  => max(1, $durationUs),
                    'args' => [
                        'duration_ms'   => $durationMs,
                        'memory_delta'  => $memDelta,
                        'errors'        => is_array($data) ? $data['errors'] : 0,
                        'warnings'      => is_array($data) ? $data['warnings'] : 0,
                        'scene'         => $r['scene_title'],
                    ],
                ];

                $offsetUs += $durationUs;
            }
        }

        return [
            'traceEvents'     => $events,
            'displayTimeUnit' => 'ms',
        ];
    }

    /**
     * Build timeline.json compatible with Chrome Trace Viewer (catapult format).
     * Each stage's start_ms is the cumulative offset of all preceding stages.
     *
     * @param array<int, array<string, mixed>> $results
     */
    private function buildTimeline(string $runId, string $domain, array $results): array
    {
        $shots = [];

        foreach ($results as $r) {
            $offset  = 0.0;
            $stages  = [];

            foreach ($r['stage_ms'] as $stageName => $data) {
                $durationMs = is_array($data) ? $data['ms'] : (float) $data;
                $stages[]   = [
                    'stage'       => $stageName,
                    'start_ms'    => round($offset, 3),
                    'end_ms'      => round($offset + $durationMs, 3),
                    'duration_ms' => $durationMs,
                    'memory_delta' => is_array($data) ? $data['memory_delta'] : 0,
                    'errors'      => is_array($data) ? $data['errors'] : 0,
                    'warnings'    => is_array($data) ? $data['warnings'] : 0,
                ];
                $offset += $durationMs;
            }

            $shots[] = [
                'shot_id'     => $r['shot_id'],
                'scene_title' => $r['scene_title'],
                'total_ms'    => $r['compile_ms'],
                'stages'      => $stages,
            ];
        }

        return [
            'run_id'    => $runId,
            'domain'    => $domain,
            'generated' => now()->toIso8601String(),
            'shots'     => $shots,
        ];
    }

    /**
     * Average per-stage ms across all results in a run.
     *
     * @param array<int, array<string, mixed>> $results
     * @return array<string, float>
     */
    private function avgStageMsAcrossResults(array $results): array
    {
        $totals = [];
        $counts = [];

        foreach ($results as $r) {
            foreach ($r['stage_ms'] ?? [] as $stageName => $data) {
                $ms                 = is_array($data) ? $data['ms'] : (float) $data;
                $totals[$stageName] = ($totals[$stageName] ?? 0.0) + $ms;
                $counts[$stageName] = ($counts[$stageName] ?? 0) + 1;
            }
        }

        $avgs = [];
        foreach ($totals as $stageName => $total) {
            $avgs[$stageName] = round($total / $counts[$stageName], 3);
        }
        return $avgs;
    }

    /**
     * Load shot DSL suite from YAML domain pack.
     * @return array<int, array<string, mixed>>
     */
    private function loadDslSuite(string $domain, int $count): array
    {
        $yamlPath = resource_path("afos/domains/{$domain}/dsl_examples.yaml");

        if (!file_exists($yamlPath)) {
            $this->warn("Domain pack not found: {$yamlPath} — using default shot");
            return [[
                'emo' => 'CALM', 'cam' => 'WIDE', 'move' => 'STATIC',
                'dur' => 5.0, 'lens' => '85', 'light' => 'G1',
                'scene_title' => 'Default',
            ]];
        }

        $data  = Yaml::parseFile($yamlPath);
        $shots = $data['shots'] ?? [];
        $suite = [];

        for ($i = 0; $i < $count; $i++) {
            $base = $shots[$i % count($shots)];
            $base['scene_id']      = "bench_{$domain}";
            $base['shot_order']    = $i + 1;
            $base['scene_emotion'] = $base['emo'];
            $suite[] = $base;
        }

        return $suite;
    }

    /**
     * Load ground truth expected outputs for a domain, applying defaults inheritance.
     * @return array<string, array<string, mixed>>  keyed by scene_title
     */
    private function loadGroundTruth(string $domain): array
    {
        $yamlPath     = resource_path("afos/domains/{$domain}/expected_outputs.yaml");
        $defaultsPath = resource_path('afos/domains/defaults.yaml');

        if (!file_exists($yamlPath)) {
            return [];
        }

        $defaults    = file_exists($defaultsPath) ? (Yaml::parseFile($defaultsPath)['defaults'] ?? []) : [];
        $data        = Yaml::parseFile($yamlPath);
        $groundTruth = $data['ground_truth'] ?? [];

        foreach ($groundTruth as $scene => $entry) {
            $groundTruth[$scene] = array_merge($defaults, $entry);
        }

        return $groundTruth;
    }

    private function getDomainPackVersion(string $domain): ?string
    {
        $yamlPath = resource_path("afos/domains/{$domain}/dsl_examples.yaml");
        if (!file_exists($yamlPath)) {
            return null;
        }
        $data = Yaml::parseFile($yamlPath);
        return $data['version'] ?? null;
    }
}
