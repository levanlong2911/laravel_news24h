<?php

namespace App\Services\Admin;

use App\Models\CategoryContext;

/**
 * Value object trả về từ ArticlePipelineService::run().
 * Chứa mọi thứ caller cần để quyết định persist / log / review.
 */
final class PipelineResult
{
    public readonly array $parsed;

    public function __construct(
        array                            $parsed,
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

        // Token usage — for FeedbackPayload
        public readonly int              $haikuInputTokens   = 0,
        public readonly int              $haikuOutputTokens  = 0,
        public readonly int              $sonnetInputTokens  = 0,
        public readonly int              $sonnetOutputTokens = 0,
        public readonly float            $totalCostUsd       = 0.0,
    ) {
        $this->parsed = array_map(
            fn($v) => is_string($v) ? str_replace('—', '-', $v) : $v,
            $parsed
        );
    }

    public function needsReview(): bool
    {
        return $this->guardResult->needsHumanReview();
    }

    public function title(): string
    {
        return trim($this->parsed['title'] ?? '');
    }
}
