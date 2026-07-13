<?php

declare(strict_types=1);

namespace App\Console\Commands\FilmOS;

use App\Models\Article;
use App\Services\AI\FilmOS\DecisionDAG\DAGNodeType;
use App\Services\AI\FilmOS\DecisionDAG\DAGRuntime;
use App\Services\AI\FilmOS\Snapshot\ArtifactLayerBuilder;
use App\Services\AI\FilmOS\Snapshot\DeterminismManifest;
use App\Services\AI\FilmOS\Snapshot\PlanningSnapshotBuilder;
use App\Services\AI\FilmOS\Snapshot\SnapshotComposer;
use App\Services\AI\FilmOS\Snapshot\TaskDescriptor;
use App\Services\AI\FilmOS\Testing\GoldenScenarioPipeline;
use App\Services\AI\FilmOS\Evaluation\Plugins\EvaluationPlugin;
use App\Services\AI\FilmOS\Intent\IntentAssembler;
use App\Services\AI\FilmOS\Kernel\FilmKernel;
use App\Services\AI\FilmOS\Kernel\FilmTask;
use App\Services\AI\FilmOS\Kernel\MemoryManager;
use App\Services\AI\FilmOS\Kernel\Plugins\RenderPlugin;
use App\Services\AI\FilmOS\Kernel\TaskScheduler;
use App\Services\AI\FilmOS\Kernel\TaskType;
use App\Services\AI\FilmOS\Knowledge\ArticleFactAdapter;
use App\Services\AI\FilmOS\Knowledge\ClaudeFilmOSFactExtractor;
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
use App\Services\Admin\ClaudeWriterService;
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
                            {--dry-run   : Skip actual Kling API calls, use mock video URLs}
                            {--article-id= : Run against a real Article from the DB (UUID)}';

    protected $description = 'Run FilmOS Phase 1 Walking Skeleton — golden scenario or real article';

    public function handle(): int
    {
        $dryRun       = $this->option('dry-run');
        $articleId    = $this->option('article-id');
        $productionId = 'prod_golden_' . date('Ymd_His');
        $domain       = 'travel_warning';

        $this->info("FilmOS Phase 1 Walking Skeleton");
        $this->info("Production: {$productionId}");
        $this->info($dryRun ? "Mode: DRY RUN (no API calls)" : "Mode: LIVE");
        $this->newLine();

        // ── Layer 1: Facts ────────────────────────────────────────────────────
        if ($articleId !== null) {
            $article = Article::find($articleId);
            if (!$article) {
                $this->error("Article not found: {$articleId}");
                return self::FAILURE;
            }
            $this->line("[L1] Loading facts from Article: {$article->title}");
            $domain  = $this->inferDomain($article);
            $adapter = new ArticleFactAdapter(
                new ClaudeFilmOSFactExtractor(new ClaudeWriterService())
            );
            $filmFacts = $adapter->factsFor($article, $domain);
            if (empty($filmFacts)) {
                $this->error("[L1] FactExtractor returned 0 facts. Check article content.");
                return self::FAILURE;
            }
            $facts = array_map(fn($f) => $f->toArray(), $filmFacts);
            $this->line("[L1] FactGraph: " . count($facts) . " facts extracted via Claude (domain={$domain})");
        } else {
            $facts = GoldenScenarioPipeline::facts();
            $this->line("[L1] FactGraph: " . count($facts) . " facts loaded (golden scenario — hardcoded)");
        }

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
            confidence: $rawPlan->goalConfidence,
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

        $taskIds   = [];
        $filmTasks = [];
        foreach ($intents as $subGoalId => $intent) {
            $priority = $intent->evaluation->priority;
            $task     = new FilmTask(
                id:        "render_{$subGoalId}",
                type:      TaskType::RENDER,
                priority:  $priority,
                payload:   $intent,
                deadlineMs: 15000,
                dependsOn: ["intent_{$subGoalId}"],
            );
            $kernel->submit($task);
            $taskIds[]   = $task->id;
            $filmTasks[] = $task;
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

        // ── ExecutionSnapshot (Phase A + Phase F) ────────────────────────────
        $worldVersion    = hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR));
        $manifest        = DeterminismManifest::current($worldVersion);
        $descriptors     = array_map(fn(FilmTask $t) => TaskDescriptor::fromFilmTask($t), $filmTasks);

        $planningSection = (new PlanningSnapshotBuilder())->build($dag, $goalGraph, $plan, $intents, $descriptors);
        $artifactSection = (new ArtifactLayerBuilder())->build($renderResults);

        $snapshot = (new SnapshotComposer())->compose($productionId, $manifest, $planningSection, $artifactSection);

        $this->newLine();
        $this->info("── ExecutionSnapshot ────────────────────────────");
        $this->line("  Hash      : " . $snapshot->shortHash() . "…");
        $this->line("  DAG       : " . substr($snapshot->get('dagHash') ?? '', 0, 16) . "…");
        $this->line("  Goals     : " . substr($snapshot->get('goalGraphHash') ?? '', 0, 16) . "…");
        $this->line("  PromptIRs : " . substr($snapshot->get('promptHash') ?? '', 0, 16) . "…");
        $this->line("  Artifacts : " . substr($snapshot->get('artifactBundleHash') ?? '', 0, 16) . "…");
        $gaps = $snapshot->gaps();
        if (!empty($gaps)) {
            $this->line("  Gaps (not yet verified): " . implode(', ', $gaps));
        }

        // Store everything in session cache for downstream commands
        cache()->put("filmos_dag_{$productionId}",      serialize($dag),      now()->addHours(2));
        cache()->put("filmos_plan_{$productionId}",     serialize($plan),     now()->addHours(2));
        cache()->put("filmos_goals_{$productionId}",    serialize($goalGraph), now()->addHours(2));
        cache()->put("filmos_snapshot_{$productionId}", serialize($snapshot), now()->addHours(2));

        return self::SUCCESS;
    }

    /**
     * Infer FilmOS domain from the article's category slug or title keywords.
     * Defaults to 'travel_warning' when no clearer signal is found.
     */
    private function inferDomain(Article $article): string
    {
        $slug = strtolower((string) ($article->category?->slug ?? ''));

        return match (true) {
            str_contains($slug, 'travel')  => 'travel_warning',
            str_contains($slug, 'sport')   => 'sports',
            str_contains($slug, 'finance') => 'finance',
            str_contains($slug, 'health')  => 'travel_warning', // health → same template for now
            default                        => 'travel_warning',
        };
    }

}
