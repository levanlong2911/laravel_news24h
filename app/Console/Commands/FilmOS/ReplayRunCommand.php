<?php

declare(strict_types=1);

namespace App\Console\Commands\FilmOS;

use App\Services\AI\FilmOS\DecisionDAG\DAGNodeType;
use App\Services\AI\FilmOS\DecisionDAG\DecisionDAG;
use App\Services\AI\FilmOS\Snapshot\ExecutionSnapshot;
use App\Services\AI\FilmOS\Testing\GoldenScenarioPipeline;
use Illuminate\Console\Command;

/**
 * Replays a production run and verifies full determinism via ExecutionSnapshot.
 * ADR-016 Criterion 6.
 *
 * Comparison levels:
 *   Level 1  — canonicalHash() must match (fast gate)
 *   Level 2  — field-by-field diff (which layer diverged)
 *   Level 3  — per-shot prompt diff (human-readable detail, only when promptHash differs)
 *
 * Usage:
 *   php artisan filmos:replay-run {productionId}
 *   php artisan filmos:replay-run prod_golden_20260708_120000
 */
class ReplayRunCommand extends Command
{
    protected $signature = 'filmos:replay-run
                            {productionId : Production ID returned by filmos:run-golden-scenario}';

    protected $description = 'Replay a production run and compare ExecutionSnapshot (ADR-016 Criterion 6)';

    public function handle(): int
    {
        $productionId = $this->argument('productionId');

        // ── Load persisted state ───────────────────────────────────────────────
        $cachedDag      = cache()->get("filmos_dag_{$productionId}");
        $cachedSnapshot = cache()->get("filmos_snapshot_{$productionId}");

        if (!$cachedDag || !$cachedSnapshot) {
            $this->error("No cached run found for: {$productionId}");
            $this->line("Run filmos:run-golden-scenario first, then pass its production ID here.");
            return self::FAILURE;
        }

        /** @var DecisionDAG $originalDag */
        $originalDag = unserialize($cachedDag);
        /** @var ExecutionSnapshot $originalSnapshot */
        $originalSnapshot = unserialize($cachedSnapshot);

        $this->info("FilmOS Replay — ADR-016 Criterion 6");
        $this->info("Original  : {$productionId}  [{$originalSnapshot->shortHash()}…]");
        $this->newLine();

        // ── Re-run pipeline via canonical golden scenario ──────────────────────
        $this->line("Re-running planning pipeline…");
        $replaySnapshot = (new GoldenScenarioPipeline())->run("replay_{$productionId}");
        $this->line("Replay    : replay_{$productionId}  [{$replaySnapshot->shortHash()}…]");
        $this->newLine();

        // ── Level 1: Canonical hash gate ──────────────────────────────────────
        if ($originalSnapshot->canonicalHash() === $replaySnapshot->canonicalHash()) {
            $this->info("Snapshot hash: MATCH ✓");
            $this->info("Criterion 6: PASS ✓ — identical ExecutionSnapshot");
            $this->newLine();
            $this->printCoverageGaps($originalSnapshot);
            return self::SUCCESS;
        }

        // ── Level 2: Field-level diff ──────────────────────────────────────────
        $diffs = $originalSnapshot->diffWith($replaySnapshot);
        $this->warn("Snapshot hash: MISMATCH ✗");
        $this->newLine();

        $rows = [];
        foreach ($originalSnapshot->allFields() as $field) {
            if (isset($diffs[$field])) {
                $orig   = substr((string) ($diffs[$field]['original'] ?? 'null'), 0, 12);
                $replay = substr((string) ($diffs[$field]['replay']   ?? 'null'), 0, 12);
                $rows[] = [$field, '✗ diff', "{$orig}… → {$replay}…"];
            } else {
                $rows[] = [$field, '✓ match', '—'];
            }
        }
        $this->table(['Field', 'Status', 'Detail'], $rows);
        $this->newLine();

        // ── Level 3: Per-shot prompt diff (only when promptHash diverged) ──────
        if (isset($diffs['promptHash'])) {
            $this->line("Per-shot prompt diff:");
            $originalPrompts = $this->extractPrompts($originalDag);
            $replayPrompts   = $this->extractPrompts($replaySnapshot);
            $shotRows        = [];
            $identical       = 0;

            foreach ($originalPrompts as $subGoalId => $originalPrompt) {
                $newPrompt = $replayPrompts[$subGoalId] ?? null;
                $match     = $newPrompt !== null && $newPrompt === $originalPrompt;
                if ($match) {
                    $identical++;
                    $shotRows[] = [$subGoalId, '✓ identical', '—'];
                } else {
                    $shotRows[] = [$subGoalId, '✗ diff', $newPrompt === null
                        ? 'missing in replay'
                        : $this->shortDiff($originalPrompt, $newPrompt)];
                }
            }
            $this->table(['Shot', 'Result', 'Detail'], $shotRows);
            $this->line("  Prompts identical: {$identical}/" . count($originalPrompts));
            $this->newLine();
        }

        $this->error("Criterion 6: FAIL ✗ — ExecutionSnapshot diverged in: "
            . implode(', ', array_keys($diffs)));
        $this->newLine();
        $this->printCoverageGaps($originalSnapshot);
        return self::FAILURE;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function extractPrompts(DecisionDAG|ExecutionSnapshot $source): array
    {
        if ($source instanceof DecisionDAG) {
            $prompts = [];
            foreach ($source->nodesOfType(DAGNodeType::RENDER) as $node) {
                $subGoalId           = str_replace('render_', '', $node->id);
                $prompts[$subGoalId] = $node->payload['prompt'] ?? '';
            }
            return $prompts;
        }
        return [];
    }

    private function printCoverageGaps(ExecutionSnapshot $snapshot): void
    {
        $gaps = $snapshot->gaps();
        if (!empty($gaps)) {
            $this->line("Coverage gaps (not yet verified — null fields):");
            foreach ($gaps as $gap) {
                $this->line("  ⊘ {$gap}");
            }
        }
    }

    private function shortDiff(string $a, string $b): string
    {
        $wordsA = explode(' ', $a);
        $wordsB = explode(' ', $b);
        foreach ($wordsA as $i => $word) {
            if (($wordsB[$i] ?? '') !== $word) {
                $got = $wordsB[$i] ?? '(missing)';
                return "word #{$i}: \"{$word}\" → \"{$got}\"";
            }
        }
        return 'differ (len: ' . strlen($a) . ' vs ' . strlen($b) . ')';
    }
}
