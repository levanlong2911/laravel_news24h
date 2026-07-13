<?php

declare(strict_types=1);

namespace App\Console\Commands\FilmOS;

use App\Services\AI\FilmOS\Snapshot\ExecutionSnapshot;
use App\Services\AI\FilmOS\Testing\GoldenScenarioPipeline;
use Illuminate\Console\Command;

/**
 * Runs the Golden Scenario pipeline N times and verifies full determinism
 * using ExecutionSnapshot — not just PromptIR comparison.
 *
 * ADR-016 Criterion 7: GoalGraph + ExecutionGraph + Policy + PromptIRs
 * must be identical across all runs. Provider timestamps/IDs are excluded.
 *
 * Usage:
 *   php artisan filmos:verify-determinism
 *   php artisan filmos:verify-determinism --runs=5
 */
class VerifyDeterminismCommand extends Command
{
    protected $signature = 'filmos:verify-determinism
                            {--runs=10 : Number of pipeline runs to compare}';

    protected $description = 'Verify full pipeline determinism via ExecutionSnapshot (ADR-016 Criterion 7)';

    public function handle(): int
    {
        $runs     = (int) $this->option('runs');
        $pipeline = new GoldenScenarioPipeline();

        $this->info("FilmOS Determinism Verifier — ADR-016 Criterion 7");
        $this->info("ExecutionSnapshot-based (DAG + GoalGraph + PromptIRs + Scheduler + Artifacts)");
        $this->info("Running golden scenario pipeline {$runs}×...");
        $this->newLine();

        /** @var ExecutionSnapshot[] $snapshots */
        $snapshots = [];
        for ($i = 1; $i <= $runs; $i++) {
            $this->line("  Run {$i}/{$runs}…");
            $snapshots[$i] = $pipeline->run("verify_run_{$i}");
        }

        $this->newLine();

        // ── Compare canonical hashes ───────────────────────────────────────────
        $reference        = $snapshots[1];
        $refHash          = $reference->canonicalHash();
        $diverged         = [];
        $fieldDivergences = [];

        for ($i = 2; $i <= $runs; $i++) {
            if ($snapshots[$i]->canonicalHash() !== $refHash) {
                $diverged[] = $i;
                foreach (array_keys($reference->diffWith($snapshots[$i])) as $field) {
                    $fieldDivergences[$field][] = $i;
                }
            }
        }

        // ── Per-run hash table ─────────────────────────────────────────────────
        $rows = [];
        for ($i = 1; $i <= $runs; $i++) {
            $match  = $snapshots[$i]->canonicalHash() === $refHash;
            $rows[] = [
                "Run #{$i}",
                $snapshots[$i]->shortHash() . '…',
                $match ? '✓ identical' : '✗ diverged',
            ];
        }
        $this->table(['Run', 'Snapshot hash (12 chars)', 'vs Run #1'], $rows);
        $this->newLine();

        // ── Field-level divergence report ─────────────────────────────────────
        if (!empty($fieldDivergences)) {
            $this->warn("Field-level divergences:");
            foreach ($fieldDivergences as $field => $runNums) {
                $this->warn("  {$field}: diverged in run(s) " . implode(', ', $runNums));
            }
            $this->newLine();
        }

        // ── Coverage gaps ──────────────────────────────────────────────────────
        $gaps = $reference->gaps();
        if (!empty($gaps)) {
            $this->line("Coverage gaps (null fields — not yet verified):");
            foreach ($gaps as $gap) {
                $this->line("  ⊘ {$gap}");
            }
            $this->newLine();
        }

        // ── Verdict ───────────────────────────────────────────────────────────
        if (empty($diverged)) {
            $this->info("Determinism PASS — all {$runs} runs produced identical ExecutionSnapshot.");
            $this->info("Criterion 7: PASS ✓");
            return self::SUCCESS;
        }

        $identical = $runs - count($diverged);
        $this->error("Determinism FAIL — {$identical}/{$runs} runs identical. "
            . "Diverged: runs " . implode(', ', $diverged));
        $this->error("Criterion 7: FAIL ✗");
        return self::FAILURE;
    }
}
