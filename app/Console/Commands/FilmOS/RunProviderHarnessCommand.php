<?php

declare(strict_types=1);

namespace App\Console\Commands\FilmOS;

use App\Services\AI\FilmOS\DecisionDAG\DAGNodeType;
use App\Services\AI\FilmOS\DecisionDAG\DAGRuntime;
use App\Services\AI\FilmOS\EventBus\EventBus;
use App\Services\AI\FilmOS\Evaluation\Plugins\EvaluationPlugin;
use App\Services\AI\FilmOS\ExecutionGraph\InMemoryCheckpointStore;
use App\Services\AI\FilmOS\Intent\IntentAssembler;
use App\Services\AI\FilmOS\Kernel\Plugins\RenderPlugin;
use App\Services\AI\FilmOS\Learning\StubPredictiveLearning;
use App\Services\AI\FilmOS\Meaning\ContextualMeaningResolver;
use App\Services\AI\FilmOS\Narrative\NarrativeStructureBuilder;
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
use App\Services\AI\FilmOS\Provider\HarnessResult;
use App\Services\AI\FilmOS\Provider\MockKlingProvider;
use App\Services\AI\FilmOS\Provider\ProviderFailureMode;
use App\Services\AI\FilmOS\Provider\ProviderHarness;
use App\Services\AI\FilmOS\Snapshot\DeterminismManifest;
use App\Services\AI\FilmOS\Snapshot\SnapshotComposer;
use App\Services\AI\FilmOS\Snapshot\TaskDescriptor;
use App\Services\AI\FilmOS\Snapshot\PlanningSnapshotBuilder;
use Illuminate\Console\Command;

/**
 * Phase B Provider Test Harness (ADR-016).
 *
 * Runs the golden scenario planning pipeline, then executes RENDER tasks
 * through MockKlingProvider via ExecutionRuntime. Produces an ExecutionSnapshot
 * with BOTH Phase A (planning) and Phase B (execution) hashes populated.
 *
 * Modes:
 *   ok             — all renders succeed (golden path)
 *   server_error   — shot F2 returns 500 (FAILED node, others complete)
 *   cascade        — shot F1 fails + --chain → F2/F3/F4 cascade SKIPPED
 *
 * Usage:
 *   php artisan filmos:run-provider-harness
 *   php artisan filmos:run-provider-harness --mode=server_error
 *   php artisan filmos:run-provider-harness --mode=cascade --chain
 */
class RunProviderHarnessCommand extends Command
{
    protected $signature = 'filmos:run-provider-harness
                            {--mode=ok        : Failure mode: ok | server_error | timeout | cascade}
                            {--chain          : Wire shots as sequential dependency chain}';

    protected $description = 'Phase B: run render tasks through MockKlingProvider + build ExecutionSnapshot with execution hashes';

    public function handle(): int
    {
        $mode  = $this->option('mode');
        $chain = (bool) $this->option('chain');

        $this->info("FilmOS Provider Harness — ADR-016 Phase B");
        $this->info("Mode: {$mode}" . ($chain ? ' + chain dependencies' : ''));
        $this->newLine();

        // ── Planning pipeline (Phase A) ────────────────────────────────────────
        $facts  = $this->goldenScenarioFacts();
        $domain = 'travel_warning';
        $runId  = 'harness_' . date('Ymd_His');

        $resolver  = new ContextualMeaningResolver();
        $meaning   = $resolver->resolve($facts, $domain);

        $narrative  = (new NarrativeStructureBuilder())->build($meaning);
        $decomposer = new GoalDecomposer();
        $goalGraph  = $decomposer->decompose($narrative);
        $leaves     = $goalGraph->leaves();

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

        // ── DAGRuntime — Phase A WHY layer ─────────────────────────────────────
        $dagRuntime = new DAGRuntime($runId);
        foreach ($facts as $fact) {
            $dagRuntime->execute($fact['id'], DAGNodeType::FACT,
                fn() => $fact, $fact['text'], [], $fact['confidence']);
        }
        $dagRuntime->execute('meaning_graph', DAGNodeType::MEANING,
            fn() => $meaning, "CausalMeaningGraph: domain={$domain}",
            array_column($facts, 'id'), 0.91);
        $dagRuntime->execute('strategy_plan', DAGNodeType::PLAN,
            fn() => $plan, 'MultiObjectiveOptimizer', ['meaning_graph'], $rawPlan->goalConfidence);

        $assembler   = new IntentAssembler();
        $intents     = [];
        $descriptors = [];

        foreach ($ordered as $shot) {
            $intent = $dagRuntime->execute(
                "intent_{$shot->subGoalId}", DAGNodeType::INTENT,
                fn() => $assembler->assemble($runId, "dag_{$runId}", $shot, $meaning, $facts),
                "DirectorIntent: {$shot->subGoalId}",
                ['strategy_plan'], 0.91,
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

        // REVIEW nodes (Phase A — for DAG completeness)
        $evaluator = new EvaluationPlugin();
        foreach ($intents as $subGoalId => $intent) {
            $renderOutput = [
                'prompt'   => RenderPlugin::buildPromptFromIntent($intent),
                'videoUrl' => "https://mock.kling.ai/{$subGoalId}.mp4",
            ];
            $dagRuntime->execute(
                "review_{$subGoalId}", DAGNodeType::REVIEW,
                fn() => $evaluator->review($intent, $renderOutput),
                "MultiAgentReview for {$intent->shotId}",
                ["intent_{$subGoalId}"], 0.89,
            );
        }

        $dag = $dagRuntime->toDecisionDAG();

        // ── Phase B: ExecutionRuntime through MockKlingProvider ────────────────
        $this->line("Running ExecutionRuntime with MockKlingProvider (mode={$mode})…");

        $provider = $this->buildProvider($mode, array_keys($intents));
        $eventBus = new EventBus(recordHistory: true);
        $harness  = new ProviderHarness($provider, new InMemoryCheckpointStore(), $eventBus);

        $harnessResult = $harness->run($runId, $intents, $chain);

        // ── Compose Phase A + Phase B ExecutionSnapshot ────────────────────────
        $worldVersion    = hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR));
        $manifest        = DeterminismManifest::current($worldVersion);
        $planningSection = (new PlanningSnapshotBuilder())->build($dag, $goalGraph, $plan, $intents, $descriptors);

        $snapshot = (new SnapshotComposer())->compose(
            $runId,
            $manifest,
            $planningSection,
            $harnessResult->executionSection,
        );

        // ── Report ─────────────────────────────────────────────────────────────
        $this->newLine();
        $this->printExecutionTable($harnessResult);
        $this->newLine();
        $this->printSnapshotTable($snapshot, $harnessResult);
        $this->newLine();
        $this->printEventHistory($eventBus);

        // ── Coverage gaps ──────────────────────────────────────────────────────
        $gaps = $snapshot->gaps();
        if (!empty($gaps)) {
            $this->line("Coverage gaps (null — not yet wired):");
            foreach ($gaps as $gap) {
                $this->line("  ⊘ {$gap}");
            }
            $this->newLine();
        }

        // ── Verdict ───────────────────────────────────────────────────────────
        if ($harnessResult->isFullyCompleted()) {
            $this->info("Phase B PASS — all renders completed. ExecutionSnapshot fully populated.");
            return self::SUCCESS;
        }

        $failed  = count($harnessResult->graph->failedNodes());
        $skipped = count($harnessResult->graph->skippedNodes());
        $this->warn("Phase B PARTIAL — {$failed} failed, {$skipped} skipped (mode={$mode} expected).");
        return self::SUCCESS; // partial failure is expected in error-mode tests
    }

    // ── Provider factory ──────────────────────────────────────────────────────

    private function buildProvider(string $mode, array $subGoalIds): MockKlingProvider
    {
        $provider = new MockKlingProvider();

        match ($mode) {
            'ok'           => null, // no failures forced
            'server_error' => $provider->forceFailure(
                'render_' . ($subGoalIds[1] ?? $subGoalIds[0]),
                ProviderFailureMode::SERVER_ERROR,
            ),
            'timeout'      => $provider->forceFailure(
                'render_' . ($subGoalIds[2] ?? $subGoalIds[0]),
                ProviderFailureMode::TIMEOUT,
            ),
            'cascade'      => $provider->forceFailure(
                'render_' . $subGoalIds[0],
                ProviderFailureMode::SERVER_ERROR,
            ),
            default        => $this->warn("Unknown mode '{$mode}', defaulting to ok."),
        };

        return $provider;
    }

    // ── Display helpers ───────────────────────────────────────────────────────

    private function printExecutionTable(HarnessResult $result): void
    {
        $rows = [];
        foreach ($result->graph->nodes() as $node) {
            $rows[] = [
                $node->id,
                $node->status->value,
                $node->retryCount,
                $node->error ?? '—',
            ];
        }
        $this->table(['Node', 'Status', 'Retries', 'Error'], $rows);

        $m = $result->metrics;
        $this->line("  Completed: {$m->completedCount}  Failed: {$m->failedCount}  Skipped: {$m->skippedCount}");
    }

    private function printSnapshotTable(
        \App\Services\AI\FilmOS\Snapshot\ExecutionSnapshot $snapshot,
        HarnessResult $result,
    ): void {
        $this->info("ExecutionSnapshot — Phase A + Phase B:");
        $this->line("  canonicalHash : " . $snapshot->shortHash() . "…");

        $short = static fn(?string $h): string => $h !== null ? substr($h, 0, 16) . '…' : 'null';

        $phaseA = [
            ['dagHash',       $short($snapshot->get('dagHash'))],
            ['goalGraphHash', $short($snapshot->get('goalGraphHash'))],
            ['promptHash',    $short($snapshot->get('promptHash'))],
            ['schedulerHash', $short($snapshot->get('schedulerHash'))],
            ['policyHash',    $short($snapshot->get('policyHash'))],
        ];
        $phaseB = [
            ['executionTopologyHash', $short($result->executionSection->executionTopologyHash)],
            ['checkpointHash',        $short($result->executionSection->checkpointHash)],
            ['retrySequenceHash',     $short($result->executionSection->retrySequenceHash)],
        ];

        $this->line("  Phase A:");
        foreach ($phaseA as [$field, $val]) {
            $this->line("    {$field}: {$val}");
        }
        $this->line("  Phase B:");
        foreach ($phaseB as [$field, $val]) {
            $this->line("    {$field}: {$val}");
        }
    }

    private function printEventHistory(EventBus $eventBus): void
    {
        $history = $eventBus->history();
        if (empty($history)) {
            return;
        }
        $this->line("EventBus history (" . count($history) . " events):");
        foreach ($history as $event) {
            $this->line("  · " . $event->eventName());
        }
    }

    // ── Golden scenario ───────────────────────────────────────────────────────

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
