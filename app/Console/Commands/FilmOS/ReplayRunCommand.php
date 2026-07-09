<?php

declare(strict_types=1);

namespace App\Console\Commands\FilmOS;

use App\Services\AI\FilmOS\DecisionDAG\DAGNodeType;
use App\Services\AI\FilmOS\DecisionDAG\DAGRuntime;
use App\Services\AI\FilmOS\DecisionDAG\DecisionDAG;
use App\Services\AI\FilmOS\Evaluation\Plugins\EvaluationPlugin;
use App\Services\AI\FilmOS\Intent\IntentAssembler;
use App\Services\AI\FilmOS\Kernel\Plugins\RenderPlugin;
use App\Services\AI\FilmOS\Learning\StubPredictiveLearning;
use App\Services\AI\FilmOS\Meaning\ContextualMeaningResolver;
use App\Services\AI\FilmOS\Planning\Estimators\CostEstimator;
use App\Services\AI\FilmOS\Planning\Estimators\LatencyEstimator;
use App\Services\AI\FilmOS\Planning\GoalDecomposer;
use App\Services\AI\FilmOS\Planning\MultiObjectiveOptimizer;
use App\Services\AI\FilmOS\Planning\PlanObjectives;
use App\Services\AI\FilmOS\Planning\SequenceOptimizer;
use App\Services\AI\FilmOS\Planning\ShotSequencePlan;
use App\Services\AI\FilmOS\Planning\Strategies\CameraStrategy;
use App\Services\AI\FilmOS\Planning\Strategies\MotionStrategy;
use App\Services\AI\FilmOS\Planning\SubGoalPlanner;
use App\Services\AI\FilmOS\Snapshot\DeterminismManifest;
use App\Services\AI\FilmOS\Snapshot\ExecutionSnapshot;
use App\Services\AI\FilmOS\Snapshot\SnapshotComposer;
use App\Services\AI\FilmOS\Snapshot\TaskDescriptor;
use Illuminate\Console\Command;

/**
 * Replays a production run and verifies full determinism via ExecutionSnapshot.
 * ADR-016 Criterion 6.
 *
 * Comparison levels:
 *   Level 1  — canonicalHash() must match (fast gate)
 *   Level 2  — field-by-field diff (which layer diverged)
 *   Level 3  — per-shot prompt diff (human-readable detail)
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
        $cachedPlan     = cache()->get("filmos_plan_{$productionId}");
        $cachedSnapshot = cache()->get("filmos_snapshot_{$productionId}");

        if (!$cachedDag || !$cachedPlan || !$cachedSnapshot) {
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

        // ── Re-run planning pipeline ───────────────────────────────────────────
        $this->line("Re-running planning pipeline…");
        $replaySnapshot = $this->buildReplaySnapshot($productionId);
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

        $this->line("Field-level divergences:");
        $rows = [];
        $allFields = ['dagHash', 'goalGraphHash', 'promptHash', 'schedulerHash',
                      'executionGraphHash', 'checkpointHash', 'retrySequenceHash',
                      'capabilityHash', 'providerRouteHash', 'policyHash', 'eventBusHash'];

        foreach ($allFields as $field) {
            if (isset($diffs[$field])) {
                $orig   = $diffs[$field]['original'] ?? 'null';
                $replay = $diffs[$field]['replay']   ?? 'null';
                $rows[] = [$field, '✗ diff',
                    substr((string)$orig, 0, 12) . '… → ' . substr((string)$replay, 0, 12) . '…'];
            } else {
                $rows[] = [$field, '✓ match', '—'];
            }
        }
        $this->table(['Field', 'Status', 'Detail'], $rows);
        $this->newLine();

        // ── Level 3: Per-shot prompt diff (only if promptHash diverged) ────────
        if (isset($diffs['promptHash'])) {
            $this->line("Per-shot prompt diff:");
            $originalPrompts = $this->extractPrompts($originalDag);
            $replayPrompts   = $this->buildPrompts();
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

    private function buildReplaySnapshot(string $productionId): ExecutionSnapshot
    {
        $facts  = $this->goldenScenarioFacts();
        $domain = 'travel_warning';

        $resolver    = new ContextualMeaningResolver();
        $meaning     = $resolver->resolve($facts, $domain);

        $decomposer  = new GoalDecomposer();
        $goalGraph   = $decomposer->decompose($meaning, $domain);
        $leaves      = $goalGraph->leaves();

        $subGoalPlanner = new SubGoalPlanner([new CameraStrategy(), new MotionStrategy()]);
        $sequenceOpt    = new SequenceOptimizer();

        $unordered = [];
        foreach ($leaves as $leaf) {
            $unordered[] = $subGoalPlanner->plan($leaf, $meaning, []);
        }
        $ordered = $sequenceOpt->optimize($unordered, $goalGraph);

        $learning   = new StubPredictiveLearning();
        $optimizer  = new MultiObjectiveOptimizer(new CostEstimator(), new LatencyEstimator(), $learning);
        $objectives = PlanObjectives::breakingNews();
        $rawPlan    = new ShotSequencePlan("plan_replay_{$productionId}", $goalGraph, $ordered, 0.88);
        $plan       = $optimizer->optimize($rawPlan, $objectives, ['domain' => $domain]);

        // ── Full pipeline DAGRuntime — mirrors RunGoldenScenarioCommand exactly ──
        $runtime = new DAGRuntime("replay_{$productionId}");
        foreach ($facts as $fact) {
            $runtime->execute($fact['id'], DAGNodeType::FACT,
                fn() => $fact, $fact['text'], [], $fact['confidence']);
        }
        $runtime->execute('meaning_graph', DAGNodeType::MEANING,
            fn() => $meaning, "CausalMeaningGraph: domain={$domain}",
            array_column($facts, 'id'), 0.91);
        $runtime->execute('strategy_plan', DAGNodeType::PLAN,
            fn() => $plan, 'MultiObjectiveOptimizer', ['meaning_graph'], $rawPlan->goalConfidence);

        // L4: INTENT nodes
        $assembler   = new IntentAssembler();
        $intents     = [];
        $descriptors = [];

        foreach ($ordered as $shot) {
            $intent = $runtime->execute(
                "intent_{$shot->subGoalId}", DAGNodeType::INTENT,
                fn() => $assembler->assemble(
                    "replay_{$productionId}", "dag_replay_{$productionId}", $shot, $meaning, $facts
                ),
                "DirectorIntent: {$shot->subGoalId}",
                ['strategy_plan'],
                0.91,
            );
            $intents[$shot->subGoalId] = $intent;
            $descriptors[] = new TaskDescriptor(
                id:         "render_{$shot->subGoalId}",
                type:       'render',
                priority:   $intent->evaluation->priority->value,
                dependsOn:  ["intent_{$shot->subGoalId}"],
                deadlineMs: 15000.0,
            );
        }

        // L6: mock RENDER nodes (dry-run — no real API call)
        foreach ($intents as $subGoalId => $intent) {
            $prompt = RenderPlugin::buildPromptFromIntent($intent);
            $mockOutput = [
                'shotId'   => $intent->shotId,
                'videoUrl' => "https://mock.kling.ai/{$subGoalId}.mp4",
                'taskId'   => "mock_{$subGoalId}",
                'prompt'   => $prompt,
            ];
            $runtime->execute(
                "render_{$subGoalId}", DAGNodeType::RENDER,
                fn() => $mockOutput,
                "Rendered: {$intent->shotId} (replay dry-run)",
                ["intent_{$subGoalId}"],
                0.86,
            );
        }

        // L7: REVIEW nodes
        $evaluator = new EvaluationPlugin();
        foreach ($intents as $subGoalId => $intent) {
            $renderOutput = [
                'prompt'   => RenderPlugin::buildPromptFromIntent($intent),
                'videoUrl' => "https://mock.kling.ai/{$subGoalId}.mp4",
            ];
            $runtime->execute(
                "review_{$subGoalId}", DAGNodeType::REVIEW,
                fn() => $evaluator->review($intent, $renderOutput),
                "MultiAgentReview for {$intent->shotId}",
                ["render_{$subGoalId}"],
                0.89,
            );
        }

        $dag = $runtime->toDecisionDAG();

        $worldVersion = hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR));
        $manifest     = DeterminismManifest::current($worldVersion);

        return (new SnapshotComposer())->composeFromPlan(
            productionId: "replay_{$productionId}",
            manifest:     $manifest,
            dag:          $dag,
            goalGraph:    $goalGraph,
            plan:         $plan,
            intents:      $intents,
            descriptors:  $descriptors,
        );
    }

    /** Extract prompts from RENDER nodes in a persisted DecisionDAG. */
    private function extractPrompts(DecisionDAG $dag): array
    {
        $prompts = [];
        foreach ($dag->nodesOfType(DAGNodeType::RENDER) as $node) {
            $subGoalId           = str_replace('render_', '', $node->id);
            $prompts[$subGoalId] = $node->payload['prompt'] ?? '';
        }
        return $prompts;
    }

    /** Re-run planning only (no DAGRuntime) — fast prompt extraction for Level 3 diff. */
    private function buildPrompts(): array
    {
        $facts  = $this->goldenScenarioFacts();
        $domain = 'travel_warning';

        $resolver  = new ContextualMeaningResolver();
        $meaning   = $resolver->resolve($facts, $domain);
        $decomposer = new GoalDecomposer();
        $goalGraph  = $decomposer->decompose($meaning, $domain);

        $subGoalPlanner = new SubGoalPlanner([new CameraStrategy(), new MotionStrategy()]);
        $sequenceOpt    = new SequenceOptimizer();
        $unordered = [];
        foreach ($goalGraph->leaves() as $leaf) {
            $unordered[] = $subGoalPlanner->plan($leaf, $meaning, []);
        }
        $ordered = $sequenceOpt->optimize($unordered, $goalGraph);

        $assembler = new IntentAssembler();
        $prompts   = [];
        foreach ($ordered as $shot) {
            $intent                    = $assembler->assemble('replay', 'dag_replay', $shot, $meaning, $facts);
            $prompts[$shot->subGoalId] = RenderPlugin::buildPromptFromIntent($intent);
        }
        return $prompts;
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

    private function goldenScenarioFacts(): array
    {
        return [
            ['id' => 'F1', 'text' => 'Cockroach infestation found in multiple guest rooms',
             'category' => 'EVIDENCE', 'visual_relevance' => 'HIGH', 'confidence' => 0.95,
             'visual_hint' => 'cockroach on hotel bedsheet'],
            ['id' => 'F2', 'text' => 'Health department issued formal warning',
             'category' => 'RESULT', 'visual_relevance' => 'MEDIUM', 'confidence' => 0.92,
             'visual_hint' => 'official health department notice document'],
            ['id' => 'F3', 'text' => 'Travelers advised to avoid property',
             'category' => 'RESULT', 'visual_relevance' => 'MEDIUM', 'confidence' => 0.88,
             'visual_hint' => 'travel advisory overlay text'],
            ['id' => 'F4', 'text' => 'Sunset Palace Resort, Bali — 3-star rated',
             'category' => 'CONTEXT', 'visual_relevance' => 'HIGH', 'confidence' => 0.90,
             'visual_hint' => 'hotel exterior, Bali architecture'],
        ];
    }
}
