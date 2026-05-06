<?php

namespace App\Services\Admin;

use App\Services\Admin\GuardReason;

/**
 * Value Object — output của PostGuard::check().
 * Immutable. WriteArticleJob dùng isAcceptable() để quyết định publish vs human_review.
 */
final class PostGuardResult
{
    private function __construct(
        public readonly ?array $parsed,      // decoded JSON từ Sonnet, null nếu parse fail
        public readonly float  $confidence,  // 0.0–1.0 hallucination score
        public readonly bool   $acceptable,  // true → publish, false → human_review
        public readonly string $reason,      // mô tả ngắn lý do reject (để log)
    ) {}

    public static function invalid(string $reason): self
    {
        return new self(
            parsed:     null,
            confidence: 0.0,
            acceptable: false,
            reason:     $reason,
        );
    }

    public static function make(array $parsed, float $confidence, bool $acceptable, string $reason): self
    {
        return new self($parsed, $confidence, $acceptable, $reason);
    }

    // ── Convenience ───────────────────────────────────────────────────────────

    public function needsHumanReview(): bool
    {
        return !$this->acceptable;
    }

    /**
     * JSON parse fail hoặc missing required fields.
     * Retry Sonnet có ý nghĩa — lỗi ngẫu nhiên, không phải hallucination.
     */
    public function isParseFailure(): bool
    {
        return $this->parsed === null && $this->reason !== GuardReason::BLOCKED_CONTENT;
    }

    /** Pipeline gate đã reject — không retry, không lưu DB. */
    public function isBlocked(): bool
    {
        return $this->reason === GuardReason::BLOCKED_CONTENT;
    }

    public function title(): string
    {
        return $this->parsed['title'] ?? '';
    }

    public function content(): string
    {
        return $this->parsed['content'] ?? '';
    }

    public function get(string $field, mixed $default = null): mixed
    {
        return $this->parsed[$field] ?? $default;
    }
}
