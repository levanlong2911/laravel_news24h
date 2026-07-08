<?php

declare(strict_types=1);

namespace App\Console\Commands\FilmOS;

use App\Services\AI\FilmOS\DecisionDAG\DAGNodeType;
use App\Services\AI\FilmOS\DecisionDAG\DAGRuntime;
use App\Services\AI\FilmOS\Evaluation\Plugins\EvaluationPlugin;
use App\Services\AI\FilmOS\Intent\IntentAssembler;
use App\Services\AI\FilmOS\Kernel\FilmKernel;
use App\Services\AI\FilmOS\Kernel\FilmTask;
use App\Services\AI\FilmOS\Kernel\MemoryManager;
use App\Services\AI\FilmOS\Kernel\Plugins\RenderPlugin;
use App\Services\AI\FilmOS\Kernel\TaskScheduler;
use App\Services\AI\FilmOS\Kernel\TaskType;
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
use App\Services\AI\FilmOS\Graph\GraphValidation;
use App\Services\AI\Provider\Kling\KlingVideoProvider;
use Illuminate\Console\Command;

/**
 * Phase 1 Walking Skeleton: runs the Golden Scenario end-to-end.
 * Success = 4 clips rendered, DAG has no orphans, trace-back complete.
 *
 * Usage:
 *   php artisan filmos:run-golden-scenario
 *   php artisan filmos:run-golden-scenario --dry-run   (skip actual Kling API calls)
 */
class RunGoldenScenarioCommand extends Command
{
    protected $signature = 'filmos:run-golden-scenario
                            {--dry-run : Skip actual Kling API calls, use mock video URLs}';

    protected $description = 'Run FilmOS Phase 1 Walking Skeleton on the Golden Scenario (cockroach hotel)';

    public function handle(): int
    {
        $dryRun       = $this->option('dry-run');
        $productionId = 'prod_golden_' . date('Ymd_His');
        $domain       = 'travel_warning';

        $this->info("FilmOS Phase 1 Walking Skeleton");
        $this->info("Production: {$productionId}");
        $this->info($dryRun ? "Mode: DRY RUN (no API calls)" : "Mode: LIVE");
        $this->newLine();

        // ── Facts (Golden Scenario Layer 0→1) ─────────────────────────────────
        $facts = $this->goldenScenarioFacts();
        $this->line("[L1] FactGraph: " . count($facts) . " facts loaded");

        // ── DAGRuntime (cross-cutting — wraps everything) ─────────────────────
        $runtime = new DAGRuntime($productionId);

        // Record FACT nodes in DAG
        foreach ($facts as $fact) {
            $runtime->execute(
                nodeId:     $fact['id'],
                type:       DAGNodeType::FACT,
                operation:  fn() => $fact,
                rationale:  $fact['text'],
                confidence: $fact['confidence'],
            );
        }

        // ── Layer 2: MeaningGraph ──────────────────────────────────────────────
        $this->line("[L2] Resolving CausalMeaningGraph…");
        $resolver = new ContextualMeaningResolver();
        $meaning  = $runtime->execute(
            nodeId:    'meaning_graph',
            type:      DAGNodeType::MEANING,
            operation: fn() => $resolver->resolve($facts, $domain),
            rationale: "CausalMeaningGraph: domain={$domain}, function=REVEAL",
            parentIds: array_column($facts, 'id'),
            confidence: 0.91,
        );
        $this->line("  root={$meaning->rootNodeId}, confidence={$meaning->confidence}, "
            . "function={$meaning->cinematicFunction->value}");

        // ── Layer 3: GoalGraph + Planning ──────────────────────────────────────
        $this->line("[L3] Decomposing GoalGraph…");
        $decomposer = new GoalDecomposer();
        $goalGraph  = $decomposer->decompose($meaning, $domain);
        $leaves     = $goalGraph->leaves();
        $this->line("  leaves=" . count($leaves) . ", totalShots={$goalGraph->totalShots()}");

        $subGoalPlanner = new SubGoalPlanner([new CameraStrategy(), new MotionStrategy()]);
        $sequenceOpt    = new SequenceOptimizer();
        $learning       = new StubPredictiveLearning();
        $optimizer      = new MultiObjectiveOptimizer(new CostEstimator(), new LatencyEstimator(), $learning);
        $objectives     = PlanObjectives::breakingNews();

        $this->line("[L3] Planning shots for each leaf goal…");
        $unordered = [];
        foreach ($leaves as $leaf) {
            $unordered[] = $subGoalPlanner->plan($leaf, $meaning, []);
        }

        $ordered = $sequenceOpt->optimize($unordered, $goalGraph);

        $rawPlan = new ShotSequencePlan(
            planId:         "plan_{$domain}_001",
            goalGraph:      $goalGraph,
            shots:          $ordered,
            goalConfidence: 0.88,
        );

        $this->line("[L3] MultiObjectiveOptimizer scoring plan…");
        $plan = $runtime->execute(
            nodeId:    'strategy_plan',
            type:      DAGNodeType::PLAN,
            operation: fn() => $optimizer->optimize($rawPlan, $objectives, ['domain' => $domain]),
            rationale: "MultiObjectiveOptimizer: breaking_news preset, 4 objectives",
            parentIds: ['meaning_graph'],
            confidence: $plan->goalConfidence ?? 0.88,
        );

        $score = $plan->score;
        $this->line("  cost=\${$score->estimatedCostUsd}, "
            . "latency={$score->estimatedLatencyMs}ms, "
            . "reviewScore={$score->expectedReviewScore}, "
            . "composite={$score->composite}");
        $this->line("  meetsHardCaps=" . ($plan->meetsHardCaps($objectives) ? 'YES' : 'NO'));

        // ── Layer 4: DirectorIntents ───────────────────────────────────────────
        $this->line("[L4] Assembling DirectorIntents…");
        $assembler = new IntentAssembler();
        $intents   = [];

        foreach ($plan->shots as $shot) {
            $intent = $runtime->execute(
                nodeId:    "intent_{$shot->subGoalId}",
                type:      DAGNodeType::INTENT,
                operation: fn() => $assembler->assemble($productionId, 'dag_' . $productionId, $shot, $meaning, $facts),
                rationale: "DirectorIntent: {$shot->subGoalId}, beat={$shot->rationale}",
                parentIds: ['strategy_plan'],
                confidence: $meaning->confidence,
            );
            $intents[$shot->subGoalId] = $intent;
            $this->line("  → {$intent->shotId}: {$intent->execution->visualStrategy->value}, "
                . "lens={$intent->execution->styleRule['lens']}mm");
        }

        // ── Layers 5+6: FilmKernel + RenderPlugin ─────────────────────────────
        $this->line("[L6] Submitting RENDER tasks to FilmKernel…");

        $provider = $dryRun ? null : KlingVideoProvider::fromConfig();
        $kernel   = new FilmKernel(new TaskScheduler(), new MemoryManager());

        if (!$dryRun) {
            $kernel->registerPlugin(new RenderPlugin($provider));
        }

        $taskIds = [];
        foreach ($intents as $subGoalId => $intent) {
            $priority = $intent->evaluation->priority;
            $task     = new FilmTask(
                id:       "render_{$subGoalId}",
                type:     TaskType::RENDER,
                priority: $priority,
                payload:  $intent,
            );
            $kernel->submit($task);
            $taskIds[] = $task->id;
        }

        $renderResults = [];
        if ($dryRun) {
            $this->line("  [DRY RUN] Skipping Kling API — using mock URLs");
            foreach ($intents as $subGoalId => $intent) {
                $taskId = "render_{$subGoalId}";
                $mockOutput = [
                    'shotId'   => $intent->shotId,
                    'videoUrl' => "https://mock.kling.ai/{$subGoalId}.mp4",
                    'taskId'   => "mock_{$subGoalId}",
                    'prompt'   => RenderPlugin::buildPromptFromIntent($intent),
                ];
                $renderResults[$taskId] = $mockOutput;

                $runtime->execute(
                    nodeId:    "render_{$subGoalId}",
                    type:      DAGNodeType::RENDER,
                    operation: fn() => $mockOutput,
                    rationale: "Rendered: {$intent->shotId} (dry-run)",
                    parentIds: ["intent_{$subGoalId}"],
                    confidence: 0.86,
                );
            }
        } else {
            $results = $kernel->runAll();
            foreach ($results as $taskId => $result) {
                if (!$result->success) {
                    $this->error("  FAILED {$taskId}: {$result->error}");
                    return self::FAILURE;
                }
                $renderResults[$taskId] = $result->output;
                $subGoalId = str_replace('render_', '', $taskId);
                $runtime->execute(
                    nodeId:    $taskId,
                    type:      DAGNodeType::RENDER,
                    operation: fn() => $result->output,
                    rationale: "Rendered: {$result->output['shotId']} in {$result->durationMs}ms",
                    parentIds: ["intent_{$subGoalId}"],
                    confidence: 0.86,
                );
                $this->line("  ✓ {$result->output['shotId']}: {$result->output['videoUrl']}");
            }
        }

        // ── Layer 7: Evaluation ────────────────────────────────────────────────
        $this->line("[L7] Evaluating shots…");
        $evaluator     = new EvaluationPlugin();
        $reviewResults = [];

        foreach ($intents as $subGoalId => $intent) {
            $taskId       = "render_{$subGoalId}";
            $renderOutput = $renderResults[$taskId] ?? [];

            $evalResult = $runtime->execute(
                nodeId:    "review_{$subGoalId}",
                type:      DAGNodeType::REVIEW,
                operation: fn() => $evaluator->review($intent, $renderOutput),
                rationale: "MultiAgentReview for {$intent->shotId}",
                parentIds: [$taskId],
                confidence: 0.89,
            );
            $reviewResults[] = $evalResult;
            $mark = $evalResult->accepted ? '✓' : '✗';
            $this->line("  {$mark} {$intent->shotId}: score={$evalResult->score}");
        }

        // ── Layer 8: Learning ──────────────────────────────────────────────────
        $this->line("[L8] Calibrating PredictiveLearning…");
        $learning->calibrate($plan, ['review_score' => 0.89, 'ctr' => 0.071]);
        $this->line("  Calibrated (Phase 1 stub — pattern logged)");

        // ── Summary ───────────────────────────────────────────────────────────
        $dag      = $runtime->toDecisionDAG();
        $allNodes = $dag->nodes();
        $this->newLine();
        $this->info("── DAG Summary ──────────────────────────────────");
        $this->line("  Total nodes : " . count($allNodes));
        $this->line("  Has orphans : " . (GraphValidation::hasOrphans($dag) ? 'YES ❌' : 'NO ✓'));

        foreach (DAGNodeType::cases() as $type) {
            $count = count($dag->nodesOfType($type));
            if ($count > 0) {
                $this->line("  {$type->value}: {$count}");
            }
        }

        $this->newLine();
        $this->info("── Phase 1 Criterion Check ──────────────────────");
        $c1 = count($renderResults) === 4;
        $c4 = $plan->score !== null;
        $c5 = $plan->meetsHardCaps($objectives);

        $this->line("  C1 (4 clips rendered)    : " . ($c1 ? 'PASS ✓' : 'FAIL ✗'));
        $this->line("  C2 (DAG no orphans)      : " . (!GraphValidation::hasOrphans($dag) ? 'PASS ✓' : 'FAIL ✗'));
        $this->line("  C4 (PlanScore populated) : " . ($c4 ? 'PASS ✓' : 'FAIL ✗'));
        $this->line("  C5 (meetsHardCaps)       : " . ($c5 ? 'PASS ✓' : 'FAIL ✗'));
        $this->line("  C3/C6 (trace + invariants): run filmos:explain-shot / filmos:check-invariants");

        $this->newLine();
        $this->info("Production ID: {$productionId}");
        $this->line("Run: php artisan filmos:explain-shot {$productionId} shot_002_cockroach_closeup");
        $this->line("Run: php artisan filmos:check-invariants {$productionId}");

        // Store DAG in session cache for downstream commands
        cache()->put("filmos_dag_{$productionId}", serialize($dag), now()->addHours(2));
        cache()->put("filmos_plan_{$productionId}", serialize($plan), now()->addHours(2));

        return self::SUCCESS;
    }

    private function goldenScenarioFacts(): array
    {
        return [
            [
                'id'              => 'F1',
                'text'            => 'Cockroach infestation found in multiple guest rooms',
                'category'        => 'EVIDENCE',
                'visual_relevance'=> 'HIGH',
                'confidence'      => 0.95,
                'visual_hint'     => 'cockroach on hotel bedsheet',
            ],
            [
                'id'              => 'F2',
                'text'            => 'Health department issued formal warning',
                'category'        => 'RESULT',
                'visual_relevance'=> 'MEDIUM',
                'confidence'      => 0.92,
                'visual_hint'     => 'official health department notice document',
            ],
            [
                'id'              => 'F3',
                'text'            => 'Travelers advised to avoid property',
                'category'        => 'RESULT',
                'visual_relevance'=> 'MEDIUM',
                'confidence'      => 0.88,
                'visual_hint'     => 'travel advisory overlay text',
            ],
            [
                'id'              => 'F4',
                'text'            => 'Sunset Palace Resort, Bali — 3-star rated',
                'category'        => 'CONTEXT',
                'visual_relevance'=> 'HIGH',
                'confidence'      => 0.90,
                'visual_hint'     => 'hotel exterior, Bali architecture',
            ],
        ];
    }
}
