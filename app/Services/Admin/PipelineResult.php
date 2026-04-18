<?php

namespace App\Services\Admin;

use App\Models\CategoryContext;

/**
 * Value object trả về từ ArticlePipelineService::run().
 * Chứa mọi thứ caller cần để quyết định persist / log / review.
 */
final class PipelineResult
{
    public function __construct(
        public readonly array            $parsed,
        public readonly HookResult       $hookResult,
        public readonly ?CategoryContext $context,
        public readonly PostGuardResult  $guardResult,
        public readonly int              $retryCount,
        public readonly ?string          $retryReason,

        // Prompt identity — for FeedbackPayload
        public readonly string           $schemaVersion,
        public readonly string           $promptFingerprint,

        // Cleaner metrics — for FeedbackPayload
        public readonly float            $cleanerReductionRatio,
        public readonly bool             $usedHaiku,
    ) {}

    public function needsReview(): bool
    {
        return $this->guardResult->needsHumanReview();
    }

    public function title(): string
    {
        return trim($this->parsed['title'] ?? '');
    }
}
