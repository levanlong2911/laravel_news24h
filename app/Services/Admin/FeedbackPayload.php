<?php

namespace App\Services\Admin;

/**
 * Value Object — đầu vào của FeedbackService::record().
 * Immutable. Mang toàn bộ outcome của WriteArticleJob pipeline.
 *
 * Tách riêng khỏi PromptPayload vì:
 *   PromptPayload = INPUT của pipeline (system, phases, schema)
 *   FeedbackPayload = OUTPUT của pipeline (results, scores, timing)
 */
final class FeedbackPayload
{
    public function __construct(
        // ── Identity ──────────────────────────────────────────────────────────
        public readonly string  $contextId,
        public readonly string  $articleId,

        // ── Content type ──────────────────────────────────────────────────────
        public readonly string  $contentTypeDetected,

        // ── Hook Engine ───────────────────────────────────────────────────────
        public readonly int     $hookScore,
        public readonly int     $hookRank,
        public readonly int     $hookCandidates,

        // ── PostGuard ─────────────────────────────────────────────────────────
        public readonly float   $guardConfidence,  // 0.0–1.0
        public readonly string  $finalReason,      // GuardReason::OK | JSON_INVALID | MISSING_FIELDS

        // ── Retry ─────────────────────────────────────────────────────────────
        public readonly int     $retryCount,
        public readonly ?string $retryReason,      // null nếu không retry

        // ── Prompt identity ───────────────────────────────────────────────────
        public readonly string  $schemaVersion,    // PromptPayload::schemaVersion()
        public readonly string  $promptFingerprint,// PromptPayload::fingerprint()

        // ── Content quality ───────────────────────────────────────────────────
        public readonly int     $viralScore,
        public readonly int     $wordCount,        // từ trong content Sonnet trả về
        public readonly int     $processingTimeMs, // tổng thời gian từ Crawl → Save
        public readonly bool    $needsReview,

        // ── Model ─────────────────────────────────────────────────────────────
        public readonly string  $modelUsed = 'sonnet',
    ) {}
}
