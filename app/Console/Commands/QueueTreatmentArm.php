<?php

namespace App\Console\Commands;

use App\DTOs\ArticleContextDTO;
use App\DTOs\PipelineContext;
use App\Models\Article;
use App\Models\CategoryContext;
use App\Models\MediaJob;
use App\Models\VideoProject;
use App\Services\Admin\FactExtractorService;
use App\Services\AI\SceneShotPlanner\Planner as SceneShotPlanner;
use App\Services\AI\StoryPlanner\Planner as StoryPlanner;
use App\Services\AI\TransformationPlanner\Planner as TransformationPlanner;
use Illuminate\Console\Command;

/**
 * Queue articles through the treatment arm (SceneGraph pipeline → media_jobs).
 *
 * Usage:
 *   php artisan video:queue-treatment --articles=uuid1,uuid2,...
 *   php artisan video:queue-treatment --golden-dataset=path/to/golden_dataset_v1.json
 *
 * Runs: FactExtractor → TransformationPlanner → StoryPlanner → SceneShotPlanner → MediaJob(pending)
 * Python media_runtime.worker claims and renders the resulting media_jobs.
 */
class QueueTreatmentArm extends Command
{
    protected $signature = 'video:queue-treatment
        {--articles= : Comma-separated article UUIDs}
        {--golden-dataset= : Path to golden_dataset_v1.json (uses all articles in it)}
        {--dry-run : Show what would be queued without creating records}';

    protected $description = 'Queue articles through the SceneGraph treatment pipeline (creates media_jobs)';

    private const DOMAIN_FALLBACK = [
        // category name → domain string to use when CategoryContext.domain is NULL
        'Pittsburgh Steelers' => 'American Football / NFL',
        'Dallas Cowboys'      => 'American Football / NFL',
    ];

    public function __construct(
        private readonly FactExtractorService  $factExtractor,
        private readonly TransformationPlanner $transformationPlanner,
        private readonly StoryPlanner          $storyPlanner,
        private readonly SceneShotPlanner      $sceneShotPlanner,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $ids = $this->resolveArticleIds();
        if (empty($ids)) {
            $this->error('No article IDs provided. Use --articles=uuid1,uuid2 or --golden-dataset=path.');
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Queueing {$ids->count()} article(s) through treatment arm...");

        $queued = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($ids as $articleId) {
            $result = $this->processOne(trim($articleId), $dryRun);
            match ($result) {
                'queued'  => $queued++,
                'skipped' => $skipped++,
                default   => $failed++,
            };
        }

        $this->newLine();
        $this->info("Done — queued:{$queued} skipped:{$skipped} failed:{$failed}");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolveArticleIds(): \Illuminate\Support\Collection
    {
        if ($raw = $this->option('articles')) {
            return collect(explode(',', $raw))->map(fn ($id) => trim($id))->filter();
        }

        if ($path = $this->option('golden-dataset')) {
            if (!file_exists($path)) {
                $this->error("File not found: {$path}");
                return collect();
            }
            $data = json_decode(file_get_contents($path), true);
            return collect($data['articles'] ?? [])->pluck('article_id');
        }

        return collect();
    }

    private function processOne(string $articleId, bool $dryRun): string
    {
        $article = Article::with('category')->find($articleId);
        if (!$article) {
            $this->error("  [{$articleId}] Article not found — skipping");
            return 'failed';
        }

        $label = "[{$article->id}] {$article->title}";

        // Skip if a media_job is already queued/running for this article
        $existingProject = VideoProject::where('article_id', $article->id)->first();
        $existing = $existingProject
            ? MediaJob::where('project_id', $existingProject->id)
                ->whereIn('status', ['pending', 'claimed', 'rendering', 'completed'])
                ->first()
            : null;

        if ($existing) {
            $this->warn("  {$label} → already has a media_job — skipping");
            return 'skipped';
        }

        if ($dryRun) {
            $this->line("  {$label} → would queue");
            return 'queued';
        }

        try {
            // 1. Facts
            $facts = $article->articleFact ?? $this->factExtractor->run($article);

            // 2. Domain
            $ctx     = CategoryContext::where('category_id', $article->category_id)->first();
            $domain  = $ctx?->domain ?: (self::DOMAIN_FALLBACK[$article->category?->name ?? ''] ?? ($article->category?->name ?? 'general'));

            // 3. VideoProject (idempotent)
            $project = VideoProject::firstOrCreate(
                ['article_id' => $article->id],
                ['status' => 'pending', 'video_type' => 'scene_graph'],
            );

            // 4. Pipeline context
            $pipeline = new PipelineContext(projectId: (string) $project->id);

            // 5. Article context DTO
            $artCtx = ArticleContextDTO::fromModels($article, $facts, $domain);

            // 6. TransformationPlanner (rule-based, free)
            $transformation = $this->transformationPlanner->run($artCtx, $pipeline, $project);

            // 7. StoryPlanner (Claude Sonnet)
            $story = $this->storyPlanner->run($artCtx, $transformation, $pipeline, $project);

            // 8. SceneShotPlanner (Claude Haiku + rule engine)
            $scenes = $this->sceneShotPlanner->run($story, $pipeline, $project);

            // 9. Create MediaJob
            MediaJob::create([
                'project_id'       => $project->id,
                'job_type'         => 'render',
                'priority'         => 5,
                'status'           => 'pending',
                'workflow_version' => $pipeline->workflowVersion,
                'planner_version'  => $pipeline->plannerVersion,
                'compiler_version' => $pipeline->compilerVersion,
                'contract_version' => $pipeline->contractVersion,
                'planning_ms'      => 0,
            ]);

            $shotCount = count($scenes);
            $this->line("  {$label} → queued ({$shotCount} scenes → media_job pending)");
            return 'queued';
        } catch (\Throwable $e) {
            $this->error("  {$label} → FAILED: {$e->getMessage()}");
            \Illuminate\Support\Facades\Log::error('[QueueTreatmentArm] Failed', [
                'article_id' => $articleId,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);
            return 'failed';
        }
    }
}
