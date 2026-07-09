<?php

declare(strict_types=1);

namespace App\Console\Commands\FilmOS;

use App\Services\AI\FilmOS\DecisionDAG\DAGNodeType;
use App\Services\AI\FilmOS\DecisionDAG\DAGRuntime;
use App\Services\AI\FilmOS\Evaluation\Plugins\EvaluationPlugin;
use App\Services\AI\FilmOS\Intent\IntentAssembler;
use App\Services\AI\FilmOS\Kernel\Plugins\RenderPlugin;
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
use App\Services\AI\FilmOS\Learning\StubPredictiveLearning;
use App\Services\AI\FilmOS\Snapshot\DeterminismManifest;
use App\Services\AI\FilmOS\Snapshot\ExecutionSnapshot;
use App\Services\AI\FilmOS\Snapshot\SnapshotComposer;
use App\Services\AI\FilmOS\Snapshot\TaskDescriptor;
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
        $runs = (int) $this->option('runs');

        $this->info("FilmOS Determinism Verifier — ADR-016 Criterion 7");
        $this->info("ExecutionSnapshot-based (DAG + GoalGraph + PromptIRs + Scheduler)");
        $this->info("Running golden scenario pipeline {$runs}×...");
        $this->newLine();

        /** @var ExecutionSnapshot[] $snapshots */
        $snapshots = [];

        for ($i = 1; $i <= $runs; $i++) {
            $this->line("  Run {$i}/{$runs}…");
            $snapshots[$i] = $this->runPipelineSnapshot("verify_run_{$i}");
        }

        $this->newLine();

        // ── Compare canonical hashes ───────────────────────────────────────────
        $reference     = $snapshots[1];
        $refHash       = $reference->canonicalHash();
        $diverged      = [];
        $fieldDivergences = [];

        for ($i = 2; $i <= $runs; $i++) {
            if ($snapshots[$i]->canonicalHash() !== $refHash) {
                $diverged[] = $i;
                $diff = $reference->diffWith($snapshots[$i]);
                foreach (array_keys($diff) as $field) {
                    $fieldDivergences[$field][] = $i;
                }
            }
        }

        // ── Per-run hash table ─────────────────────────────────────────────────
        $rows = [];
        for ($i = 1; $i <= $runs; $i++) {
            $hash  = $snapshots[$i]->shortHash();
            $match = $snapshots[$i]->canonicalHash() === $refHash;
            $rows[] = [
                "Run #{$i}",
                $hash . '…',
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

        // ── Coverage gaps (what's not yet verified) ────────────────────────────
        $gaps = $reference->gaps();
        if (!empty($gaps)) {
            $this->line("Coverage gaps (null fields — not yet verified):");
            foreach ($gaps as $gap) {
                $this->line("  ⊘ {$gap}");
            }
            $this->newLine();
        }

        // ── Verdict ───────────────────────────────────────────────────────────
        $identical = $runs - count($diverged);
        if (empty($diverged)) {
            $this->info("Determinism PASS — all {$runs} runs produced identical ExecutionSnapshot.");
            $this->info("Criterion 7: PASS ✓");
            return self::SUCCESS;
        }

        $this->error("Determinism FAIL — {$identical}/{$runs} runs identical. "
            . "Diverged: runs " . implode(', ', $diverged));
        $this->error("Criterion 7: FAIL ✗");
        return self::FAILURE;
    }

    /**
     * Run the full planning pipeline once and return its ExecutionSnapshot.
     */
    private function runPipelineSnapshot(string $runId): ExecutionSnapshot
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
        $rawPlan    = new ShotSequencePlan("plan_{$runId}", $goalGraph, $ordered, 0.88);
        $plan       = $optimizer->optimize($rawPlan, $objectives, ['domain' => $domain]);

        // ── Full pipeline DAGRuntime — mirrors RunGoldenScenarioCommand exactly ──
        $runtime = new DAGRuntime($runId);
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
                fn() => $assembler->assemble($runId, "dag_{$runId}", $shot, $meaning, $facts),
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

        // L6: mock RENDER nodes (dry-run)
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
                "Rendered: {$intent->shotId} (verify dry-run)",
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
            productionId: $runId,
            manifest:     $manifest,
            dag:          $dag,
            goalGraph:    $goalGraph,
            plan:         $plan,
            intents:      $intents,
            descriptors:  $descriptors,
        );
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
