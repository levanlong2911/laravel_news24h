<?php

declare(strict_types=1);

namespace App\Console\Commands\FilmOS;

use App\Models\Article;
use App\Services\AI\FilmOS\Intent\IntentAssembler;
use App\Services\AI\FilmOS\Knowledge\ArticleFactAdapter;
use App\Services\AI\FilmOS\Knowledge\ClaudeFilmOSFactExtractor;
use App\Services\AI\FilmOS\Meaning\ContextualMeaningResolver;
use App\Services\AI\FilmOS\Planning\Estimators\CostEstimator;
use App\Services\AI\FilmOS\Planning\Estimators\LatencyEstimator;
use App\Services\AI\FilmOS\Narrative\NarrativeStructureBuilder;
use App\Services\AI\FilmOS\Planning\GoalDecomposer;
use App\Services\AI\FilmOS\Planning\MultiObjectiveOptimizer;
use App\Services\AI\FilmOS\Planning\PlanObjectives;
use App\Services\AI\FilmOS\Planning\SequenceOptimizer;
use App\Services\AI\FilmOS\Planning\ShotSequencePlan;
use App\Services\AI\FilmOS\Planning\Strategies\CameraStrategy;
use App\Services\AI\FilmOS\Planning\Strategies\MotionStrategy;
use App\Services\AI\FilmOS\Planning\SubGoalPlanner;
use App\Services\AI\FilmOS\Pipeline\DirectorIntentToPlanningIR;
use App\Services\AI\FilmOS\Production\VideoProductionPipeline;
use App\Services\AI\FilmOS\Testing\GoldenScenarioPipeline;
use App\Services\AI\FilmOS\Learning\StubPredictiveLearning;
use App\Services\Admin\ClaudeWriterService;
use Illuminate\Console\Command;

/**
 * Phase E.5 — Production Vertical Slice
 *
 * Runs the complete pipeline from an Article (or golden scenario) to a final MP4:
 *
 *   Article
 *     ↓ FactExtractor
 *   Facts
 *     ↓ MeaningResolver
 *   MeaningGraph
 *     ↓ GoalDecomposer
 *   GoalGraph
 *     ↓ SubGoalPlanner + SequenceOptimizer
 *   PlannedShots
 *     ↓ IntentAssembler
 *   DirectorIntents + Prompts
 *     ↓ KlingVideoProvider (async submit → poll)
 *   Video clips
 *     ↓ VideoDownloadManager
 *   Local .mp4 files
 *     ↓ FfmpegPipeline (normalize → concat)
 *   output.mp4
 *
 * Usage:
 *   php artisan filmos:produce --article-id=<uuid>
 *   php artisan filmos:produce --article-id=<uuid> --domain=sports
 *   php artisan filmos:produce --dry-run               (golden scenario, no Kling, no FFmpeg)
 */
class ProduceCommand extends Command
{
    public function __construct(
        private readonly VideoProductionPipeline    $pipeline,
        private readonly DirectorIntentToPlanningIR $intentAdapter,
    ) {
        parent::__construct();
    }

    protected $signature = 'filmos:produce
                            {--article-id=  : Article UUID to produce from}
                            {--domain=      : Override domain (travel_warning, sports, finance)}
                            {--dry-run      : Use golden scenario facts; skip Kling and FFmpeg}
                            {--output-dir=  : Output directory (default: storage/app/productions/{id})}';

    protected $description = 'Full vertical slice: Article → Planning → Kling → Download → FFmpeg → output.mp4';

    public function handle(): int
    {
        $dryRun      = (bool) $this->option('dry-run');
        $articleId   = $this->option('article-id');
        $domainOpt   = $this->option('domain');

        $productionId = 'prod_' . date('Ymd_His');
        $outputDir    = $this->option('output-dir')
            ?? storage_path("app/productions/{$productionId}");

        $this->info("FilmOS Vertical Slice — Phase E.5");
        $this->info("Production : {$productionId}");
        $this->info("Output     : {$outputDir}");
        $this->info($dryRun ? "Mode       : DRY RUN (no Kling, no FFmpeg)" : "Mode       : LIVE");
        $this->newLine();

        // ── Step 1: Facts ─────────────────────────────────────────────────────
        $this->info("[1/5] Loading facts…");

        if ($dryRun || $articleId === null) {
            $facts  = GoldenScenarioPipeline::facts();
            $domain = $domainOpt ?? 'travel_warning';
            $this->line("  → Golden scenario ({$domain}): " . count($facts) . " facts");
        } else {
            $article = Article::find($articleId);
            if (!$article) {
                $this->error("Article not found: {$articleId}");
                return self::FAILURE;
            }
            $this->line("  → Article: {$article->title}");

            $domain  = $domainOpt ?? $this->inferDomain($article);
            $adapter = new ArticleFactAdapter(
                new ClaudeFilmOSFactExtractor(new ClaudeWriterService())
            );
            $filmFacts = $adapter->factsFor($article, $domain);

            if (empty($filmFacts)) {
                $this->error("FactExtractor returned 0 facts. Check article content.");
                return self::FAILURE;
            }

            $facts = array_map(fn($f) => $f->toArray(), $filmFacts);
            $this->line("  → {$domain}: " . count($facts) . " facts");
        }

        // ── Step 2: Planning ──────────────────────────────────────────────────
        $this->info("[2/5] Planning shots…");

        $resolver   = new ContextualMeaningResolver();
        $meaning    = $resolver->resolve($facts, $domain);
        $this->line("  → MeaningGraph: root={$meaning->rootNodeId}, fn={$meaning->cinematicFunction->value}, nodes={$meaning->nodeCount()}, tension={$meaning->tensionLevel}");

        $narrative  = (new NarrativeStructureBuilder())->build($meaning);
        $this->line("  → NarrativeGraph: " . implode('→', array_map(fn($b) => $b->beat, $narrative->orderedBeats())));

        $decomposer = new GoalDecomposer();
        $goalGraph  = $decomposer->decompose($narrative);
        $leaves     = $goalGraph->leaves();
        $this->line("  → GoalGraph: {$goalGraph->totalShots()} shots across " . count($leaves) . " goals");

        $subGoalPlanner = new SubGoalPlanner([new CameraStrategy(), new MotionStrategy()]);
        $sequenceOpt    = new SequenceOptimizer();
        $unordered      = [];
        foreach ($leaves as $leaf) {
            $unordered[] = $subGoalPlanner->plan($leaf, $meaning, []);
        }
        $ordered = $sequenceOpt->optimize($unordered, $goalGraph);

        $learning   = new StubPredictiveLearning();
        $optimizer  = new MultiObjectiveOptimizer(new CostEstimator(), new LatencyEstimator(), $learning);
        $objectives = PlanObjectives::breakingNews();
        $rawPlan    = new ShotSequencePlan("plan_{$productionId}", $goalGraph, $ordered, 0.88);
        $plan       = $optimizer->optimize($rawPlan, $objectives, ['domain' => $domain]);

        // ── Step 3: DirectorIntents ───────────────────────────────────────────
        $this->info("[3/5] Assembling director intents…");

        $assembler = new IntentAssembler();
        $intents   = [];
        foreach ($plan->shots as $shot) {
            $intent                    = $assembler->assemble($productionId, "dag_{$productionId}", $shot, $meaning, $facts);
            $intents[$shot->subGoalId] = $intent;
            $this->line("  → {$intent->shotId}: {$intent->execution->visualStrategy->value}");
        }

        $this->line("  Total: " . count($intents) . " shots to render");

        // ── Step 4: Render + Download ─────────────────────────────────────────
        $this->info("[4/5] Rendering & downloading…");

        if ($dryRun) {
            $this->line("  [DRY RUN] Skipping Kling API — mock shots:");
            foreach ($intents as $shotId => $intent) {
                $this->line("    → render_{$shotId}: https://mock.kling.ai/{$shotId}.mp4");
            }
            $this->newLine();
            $this->info("[5/5] Skipping FFmpeg (dry-run)");
            $this->newLine();
            $this->info("DRY RUN complete — {$productionId}");
            $this->line("  " . count($intents) . " shots planned. Run without --dry-run to render for real.");
            return self::SUCCESS;
        }

        $planningIRs = $this->intentAdapter->convertBatch($intents, $productionId);

        $result = $this->pipeline->produce(
            planningIRs:  $planningIRs,
            productionId: $productionId,
            outputDir:    $outputDir,
            onProgress:   fn(string $msg) => $this->line($msg),
        );

        // ── Step 5: Report ────────────────────────────────────────────────────
        $this->info("[5/5] Done.");
        $this->newLine();

        $this->info("── Production Result ────────────────────────────");
        $this->line("  Production  : {$result->productionId}");
        $this->line("  Shots       : {$result->renderedShots}/{$result->totalShots} rendered");
        $this->line("  Elapsed     : " . sprintf("%.1f", $result->elapsedSeconds) . "s");

        if ($result->outputPath !== null) {
            $size = file_exists($result->outputPath) ? filesize($result->outputPath) : 0;
            $this->line("  Output      : {$result->outputPath} (" . round($size / 1_048_576, 1) . " MB)");
        }

        if (!empty($result->renderErrors)) {
            $this->newLine();
            $this->warn("Render failures:");
            foreach ($result->renderErrors as $shotId => $error) {
                $this->warn("  ✗ {$shotId}: {$error}");
            }
        }

        if ($result->ffmpegError !== null) {
            $this->newLine();
            $this->error("FFmpeg error: {$result->ffmpegError}");
        }

        $this->newLine();

        if ($result->success) {
            $this->info("SUCCESS — {$result->summary()}");
            return self::SUCCESS;
        }

        $this->error("FAILED — {$result->summary()}");
        return self::FAILURE;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function inferDomain(Article $article): string
    {
        $slug = strtolower((string) ($article->category?->slug ?? ''));
        return match (true) {
            str_contains($slug, 'sport')   => 'sports',
            str_contains($slug, 'finance') => 'finance',
            default                        => 'travel_warning',
        };
    }
}
