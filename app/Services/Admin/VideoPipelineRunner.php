<?php

namespace App\Services\Admin;

use App\Exceptions\VideoFrameworkNotConfiguredException;
use App\Models\Article;
use Illuminate\Support\Facades\Cache;
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
     * L10 — viral_score drives total_parts:
     *   >= 70 → 3 parts (high viral, worth multi-part cliffhanger series)
     *   >= 40 → 2 parts (medium engagement)
     *   <  40 → 1 part  (low reach, single short video only)
     * Capped by config('video.parts_per_topic', 3) so a future config
     * change (e.g. max 5) automatically unlocks more parts for viral content.
     */
    public function resolveTotalParts(Article $article): int
    {
        $score = (int) ($article->viral_score ?? 0);
        $max   = (int) config('video.parts_per_topic', 3);

        $parts = match(true) {
            $score >= 70 => 3,
            $score >= 40 => 2,
            default      => 1,
        };

        return min($parts, $max);
    }

    public function run(Article $article, int $partsPerTopic = 0): array
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

        $article    = $article->fresh();
        $totalParts = $partsPerTopic > 0 ? $partsPerTopic : $this->resolveTotalParts($article);
        $cacheKey   = "video_pipeline_step:{$article->id}";

        try {
            Cache::put($cacheKey, 'extracting_facts', 600);
            $facts = $article->articleFact ?? $this->factExtractor->run($article);

            Cache::put($cacheKey, 'planning_story', 600);
            $plan = $article->storyPlan ?? $this->storyPlanner->run($article, $facts, $totalParts);

            Cache::put($cacheKey, 'writing_script', 600);
            $this->scriptGenerator->run($article, $facts, $plan);

            Cache::forget($cacheKey);
            return ['status' => 'ok', 'message' => "{$plan->total_parts} part(s) scripted"];
        } catch (VideoFrameworkNotConfiguredException $e) {
            Cache::forget($cacheKey);
            $this->skipPermanently($article, $e->getMessage());
            Log::info('[VideoPipelineRunner] Skipping article permanently: ' . $e->getMessage(), [
                'article_id' => $article->id,
            ]);

            return ['status' => 'skipped', 'message' => $e->getMessage()];
        } catch (\Throwable $e) {
            Cache::forget($cacheKey);
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
    public function forceRetry(Article $article): array
    {
        if ($article->video_skipped_at || $article->video_failure_count > 0) {
            $article->update([
                'video_skipped_at' => null,
                'video_skip_reason' => null,
                'video_failure_count' => 0,
            ]);
        }

        return $this->run($article);
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
        $article->refresh(); // sync in-memory value with the DB-incremented value

        if ($article->video_failure_count >= self::MAX_FAILURES) {
            $this->skipPermanently($article, "Max retries exceeded ({$article->video_failure_count}): {$reason}");
        }
    }
}
