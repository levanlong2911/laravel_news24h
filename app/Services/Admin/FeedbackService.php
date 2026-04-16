<?php

namespace App\Services\Admin;

use App\Models\CategoryContext;
use App\Models\PromptMetric;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FeedbackService — đóng vòng lặp giữa pipeline output và prompt config.
 *
 * Flow:
 *   WriteArticleJob (cuối pipeline)
 *     → FeedbackService::record(FeedbackPayload)
 *         → PromptMetric::create()                  — lưu chi tiết từng run
 *         → CategoryContext: performance_score + sample_size  — EMA + counter
 *
 * EMA cold start:
 *   context mới (sample_size = 0 hoặc score = NULL) → dùng viral_score trực tiếp
 *   thay vì EMA → tránh "chìm" quá lâu khi chỉ có vài bài đầu tiên.
 *
 * sample_size:
 *   score 80 từ 2 bài ≠ score 80 từ 100 bài — cần biết độ tin cậy.
 *
 * summary() cache:
 *   5 phút (config: feedback.summary_cache_ttl) — dashboard load nhanh.
 *   Invalidate sau mỗi record() để data không stale khi có bài mới.
 *
 * EMA alpha configurable:
 *   config('feedback.ema_alpha') = 0.1 (default)
 *   Tune trong .env: FEEDBACK_EMA_ALPHA=0.2
 */
class FeedbackService
{
    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Ghi nhận outcome của 1 run pipeline.
     * Không throw — lỗi ở đây không nên làm hỏng pipeline chính.
     */
    public function record(FeedbackPayload $feedback): void
    {
        try {
            PromptMetric::create([
                'context_id'           => $feedback->contextId,
                'article_id'           => $feedback->articleId,
                'content_type_detected'=> $feedback->contentTypeDetected,
                'viral_score'          => $feedback->viralScore,
                'word_count'           => $feedback->wordCount,
                'processing_time_ms'   => $feedback->processingTimeMs,
                'model_used'           => $feedback->modelUsed,
                'hook_score'           => $feedback->hookScore,
                'hook_rank'            => $feedback->hookRank,
                'hook_candidates'      => $feedback->hookCandidates,
                'guard_confidence'     => $feedback->guardConfidence,
                'final_reason'         => $feedback->finalReason,
                'retry_count'          => $feedback->retryCount,
                'retry_reason'         => $feedback->retryReason,
                'schema_version'       => $feedback->schemaVersion,
                'prompt_fingerprint'   => $feedback->promptFingerprint,
                'needs_review'         => $feedback->needsReview,
            ]);

            $this->updatePerformanceScore($feedback->contextId, $feedback->viralScore);

            // Invalidate summary cache — data mới → cache cũ không còn hợp lệ
            $this->forgetSummaryCache($feedback->contextId);

        } catch (\Throwable $e) {
            // Soft fail — pipeline không bị ảnh hưởng
            Log::warning('[FeedbackService] Failed to record metric', [
                'article_id' => $feedback->articleId,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Aggregated summary cho 1 context — dùng ở dashboard.
     * Cache 5 phút (config: feedback.summary_cache_ttl).
     *
     * @return array{
     *   total_runs: int,
     *   sample_size: int,
     *   avg_viral_score: float,
     *   avg_hook_score: float,
     *   avg_confidence: float,
     *   avg_word_count: float,
     *   avg_processing_ms: float,
     *   retry_rate: float,
     *   review_rate: float,
     *   top_content_type: string,
     *   hook_rank_dist: array,
     *   final_reason_dist: array,
     * }
     */
    public function summary(string $contextId): array
    {
        $ttl = (int) config('feedback.summary_cache_ttl', 300);

        return Cache::remember(
            $this->summaryCacheKey($contextId),
            $ttl,
            fn () => $this->buildSummary($contextId)
        );
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Cập nhật performance_score (EMA) và sample_size — atomic SQL.
     *
     * Cold start (sample_size = 0 hoặc score IS NULL):
     *   → score = viral_score trực tiếp (tránh score bị "chìm" ở bài đầu tiên)
     *
     * Sau cold start:
     *   → EMA: score = score * (1 - α) + viral_score * α
     *
     * Dùng CASE trong DB::raw để atomic — tránh race condition khi queue parallel.
     */
    private function updatePerformanceScore(string $contextId, int $viralScore): void
    {
        $alpha      = (float) config('feedback.ema_alpha', 0.1);
        $complement = round(1 - $alpha, 10); // tránh floating point artifact

        CategoryContext::where('id', $contextId)->update([
            'performance_score' => DB::raw("
                ROUND(
                    CASE
                        WHEN sample_size = 0 OR performance_score IS NULL
                        THEN {$viralScore}
                        ELSE performance_score * {$complement} + {$viralScore} * {$alpha}
                    END,
                2)
            "),
            'sample_size' => DB::raw('sample_size + 1'),
        ]);
    }

    private function buildSummary(string $contextId): array
    {
        $window  = (int) config('feedback.summary_window', 50);
        $metrics = PromptMetric::where('context_id', $contextId)
            ->latest()
            ->limit($window)
            ->get();

        if ($metrics->isEmpty()) {
            return $this->emptySummary();
        }

        $total      = $metrics->count();
        $sampleSize = CategoryContext::where('id', $contextId)->value('sample_size') ?? $total;

        return [
            'total_runs'        => $total,
            'sample_size'       => $sampleSize,    // tổng lịch sử, không chỉ window
            'avg_viral_score'   => round($metrics->avg('viral_score'), 1),
            'avg_hook_score'    => round($metrics->avg('hook_score'), 1),
            'avg_confidence'    => round($metrics->avg('guard_confidence'), 3),
            'avg_word_count'    => (int) round($metrics->avg('word_count')),
            'avg_processing_ms' => (int) round($metrics->avg('processing_time_ms')),
            'retry_rate'        => round($metrics->where('retry_count', '>', 0)->count() / $total, 3),
            'review_rate'       => round($metrics->where('needs_review', true)->count() / $total, 3),
            'top_content_type'  => $metrics->groupBy('content_type_detected')
                ->map->count()
                ->sortDesc()
                ->keys()
                ->first() ?? 'unknown',
            'hook_rank_dist'    => $metrics->groupBy('hook_rank')
                ->map->count()
                ->sortKeys()
                ->toArray(),
            'final_reason_dist' => $metrics->groupBy('final_reason')
                ->map->count()
                ->sortDesc()
                ->toArray(),
        ];
    }

    private function summaryCacheKey(string $contextId): string
    {
        return "feedback_summary_{$contextId}";
    }

    private function forgetSummaryCache(string $contextId): void
    {
        Cache::forget($this->summaryCacheKey($contextId));
    }

    private function emptySummary(): array
    {
        return [
            'total_runs'        => 0,
            'sample_size'       => 0,
            'avg_viral_score'   => 0.0,
            'avg_hook_score'    => 0.0,
            'avg_confidence'    => 0.0,
            'avg_word_count'    => 0,
            'avg_processing_ms' => 0,
            'retry_rate'        => 0.0,
            'review_rate'       => 0.0,
            'top_content_type'  => 'unknown',
            'hook_rank_dist'    => [],
            'final_reason_dist' => [],
        ];
    }
}
