<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Services\Admin\VideoPipelineRunner;
use Illuminate\Console\Command;

/**
 * Runs Fact Extractor -> Story Planner -> Script Generator synchronously for
 * a batch of published articles, exactly like ProcessKeywordJob already runs
 * synchronously today -- QUEUE_CONNECTION stays "sync", untouched. Running
 * from a CLI Artisan command (not an HTTP request) sidesteps PHP's web
 * request execution-time limit, which was the actual problem the original
 * "switch to database queue" plan was trying to solve.
 *
 * Failure mode #5: one bad article must not stall the whole run -- each
 * article's pipeline is wrapped in its own try/catch.
 * Failure mode #7: an article whose category has no purpose=video prompt
 * framework configured is skipped with a clear log, never crashes the
 * command and never silently uses the wrong (article-writing) framework.
 *
 * Starvation fix: a permanently-broken article (no video framework
 * configured, or Claude output that never parses after VideoPipelineRunner::
 * MAX_FAILURES retries) must never be selected again -- otherwise it re-fills
 * every 15-minute batch forever and, once enough such articles accumulate
 * past batch_size, permanently starves out articles that could actually
 * succeed. Both cases are recorded on the article itself
 * (video_skipped_at/video_skip_reason) by VideoPipelineRunner and excluded
 * from the query below. orderBy() makes batch selection deterministic
 * (oldest-published-first) instead of relying on undefined row order from an
 * unordered LIMIT.
 *
 * Per-article processing itself (and the skip/retry bookkeeping) lives in
 * VideoPipelineRunner, shared with the manual "Tạo Video AI" button
 * (VideoJobController::generate()) so both entry points behave identically.
 *
 * --article=<id>: scopes this run to exactly one article and forces a retry
 * (clears any prior skip/failure state), bypassing the batch query entirely.
 * This is what VideoJobController::generate() shells out to via
 * Process::start() -- run as a detached OS process, NOT awaited, so the web
 * request that triggered it returns immediately instead of holding a PHP
 * worker for the 1-3 minutes a full Fact/Story/Script Claude chain takes.
 * Multiple employees triggering this for different articles at the same
 * moment simply become multiple independent `php artisan` processes running
 * in parallel -- they don't queue behind each other (only Claude's own
 * RPM/concurrency limit in ClaudeWriterService throttles them, same as today).
 */
class ProcessVideoArticles extends Command
{
    protected $signature = 'video:process-articles {--article= : Process exactly one article ID, forcing a retry, instead of the usual batch}';
    protected $description = 'Run Fact Extractor -> Story Planner -> Script Generator for published articles missing a video script';

    public function handle(VideoPipelineRunner $runner): int
    {
        $partsPerTopic = (int) config('video.parts_per_topic', 3);

        if ($articleId = $this->option('article')) {
            $article = Article::find($articleId);
            if (!$article) {
                $this->error("Article not found: {$articleId}");
                return self::FAILURE;
            }

            $result = $runner->forceRetry($article, $partsPerTopic);
            $this->report($article, $result);

            return self::SUCCESS;
        }

        $batchSize = (int) config('video.batch_size', 20);

        $articles = Article::published()
            ->whereNull('video_skipped_at')
            ->whereDoesntHave('storyPlan', function ($q) use ($partsPerTopic) {
                $q->has('videoJobs', '>=', $partsPerTopic);
            })
            ->orderBy('published_at')
            ->limit($batchSize)
            ->get();

        $this->info("Processing {$articles->count()} article(s) for the video pipeline...");

        foreach ($articles as $article) {
            $this->report($article, $runner->run($article, $partsPerTopic));
        }

        return self::SUCCESS;
    }

    private function report(Article $article, array $result): void
    {
        match ($result['status']) {
            'ok' => $this->line("  [{$article->id}] OK -- {$result['message']}"),
            'skipped' => $this->warn("  [{$article->id}] SKIPPED -- {$result['message']}"),
            'failed' => $this->error("  [{$article->id}] FAILED ({$article->video_failure_count}/" . VideoPipelineRunner::MAX_FAILURES . ") -- {$result['message']}"),
        };
    }
}
