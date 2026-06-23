<?php

namespace App\Services\Admin;

use App\Exceptions\VideoFrameworkNotConfiguredException;
use App\Models\Article;
use Illuminate\Support\Facades\Log;

/**
 * Single-article entry point for Fact Extractor -> Story Planner -> Script
 * Generator -- extracted out of ProcessVideoArticles so the same skip/retry
 * bookkeeping (video_skipped_at/video_skip_reason/video_failure_count) is
 * shared by both the 15-minute cron batch AND the manual "Tạo Video AI"
 * button (VideoJobController::generate()), instead of duplicating it.
 */
class VideoPipelineRunner
{
    public const MAX_FAILURES = 5;
    private const LOCK_TIMEOUT_MINUTES = 10; // a process that died without clearing the lock is treated as abandoned after this long

    public function __construct(
        private FactExtractorService $factExtractor,
        private StoryPlannerService $storyPlanner,
        private ScriptGeneratorService $scriptGenerator,
    ) {
    }

    /**
     * Two callers can race for the same article: a manually-triggered
     * background process (VideoJobController::generate()) and the
     * 15-minute cron batch, or two employees both picking the same article
     * within seconds of each other. Without a lock, both would pass the
     * "no ArticleFact/StoryPlan yet" check, both call Claude, and the
     * second INSERT would hit article_facts/story_plans' unique(article_id)
     * constraint -- wasted Claude cost, confusing failure log. The atomic
     * conditional UPDATE below (only succeeds if video_processing_started_at
     * is null or stale) is the actual lock; checking-then-setting in two
     * steps would have the exact same race it's meant to prevent.
     *
     * @return array{status: 'ok'|'skipped'|'failed', message: string}
     */
    public function run(Article $article, int $partsPerTopic): array
    {
        $acquired = Article::where('id', $article->id)
            ->where(function ($q) {
                $q->whereNull('video_processing_started_at')
                    ->orWhere('video_processing_started_at', '<', now()->subMinutes(self::LOCK_TIMEOUT_MINUTES));
            })
            ->update(['video_processing_started_at' => now()]);

        if (!$acquired) {
            return ['status' => 'failed', 'message' => 'Bài này đang được xử lý (bởi cron hoặc người khác) -- thử lại sau ít phút.'];
        }

        $article = $article->fresh();

        try {
            $facts = $article->articleFact ?? $this->factExtractor->run($article);
            $plan = $article->storyPlan ?? $this->storyPlanner->run($article, $facts, $partsPerTopic);
            $this->scriptGenerator->run($article, $facts, $plan);

            return ['status' => 'ok', 'message' => "{$plan->total_parts} part(s) scripted"];
        } catch (VideoFrameworkNotConfiguredException $e) {
            $this->skipPermanently($article, $e->getMessage());
            Log::info('[VideoPipelineRunner] Skipping article permanently: ' . $e->getMessage(), [
                'article_id' => $article->id,
            ]);

            return ['status' => 'skipped', 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            $this->recordFailure($article, $e->getMessage());
            Log::error('[VideoPipelineRunner] Article failed: ' . $e->getMessage(), [
                'article_id' => $article->id,
                'failure_count' => $article->video_failure_count,
                'trace' => $e->getTraceAsString(),
            ]);

            return ['status' => 'failed', 'message' => $e->getMessage()];
        } finally {
            $article->update(['video_processing_started_at' => null]);
        }
    }

    /**
     * Manual override entry point (the "Tạo Video AI" button): clears any
     * prior skip/failure bookkeeping so a deliberately-retried article is
     * never blocked by the same starvation guard that protects the
     * unattended cron batch from permanently-broken articles. Locking
     * itself happens inside run() -- shared with the cron path.
     */
    public function forceRetry(Article $article, int $partsPerTopic): array
    {
        if ($article->video_skipped_at || $article->video_failure_count > 0) {
            $article->update([
                'video_skipped_at' => null,
                'video_skip_reason' => null,
                'video_failure_count' => 0,
            ]);
        }

        return $this->run($article, $partsPerTopic);
    }

    private function skipPermanently(Article $article, string $reason): void
    {
        $article->update([
            'video_skipped_at' => now(),
            'video_skip_reason' => $reason,
        ]);
    }

    private function recordFailure(Article $article, string $reason): void
    {
        $article->increment('video_failure_count');

        if ($article->video_failure_count >= self::MAX_FAILURES) {
            $this->skipPermanently($article, "Max retries exceeded ({$article->video_failure_count}): {$reason}");
        }
    }
}
