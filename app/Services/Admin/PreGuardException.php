<?php

namespace App\Services\Admin;

/**
 * Thrown bởi PreGuard khi input không hợp lệ hoặc content đã tồn tại.
 * WriteArticleJob catch exception này để skip job sạch — không retry.
 */
class PreGuardException extends \RuntimeException
{
    public const REASON_EMPTY_INPUT    = 'empty_input';
    public const REASON_TOO_SHORT      = 'too_short';
    public const REASON_DUPLICATE      = 'duplicate';        // SHA-256 exact match
    public const REASON_NEAR_DUPLICATE = 'near_duplicate';   // SimHash Hamming ≤ HARD (~91% sim) → skip
    public const REASON_SOFT_DUPLICATE = 'soft_duplicate';   // SimHash Hamming ≤ SOFT (~80-90% sim) → review

    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    /** true = skip pipeline hoàn toàn (exact / hard near-dup) */
    public function isDuplicate(): bool
    {
        return in_array($this->reason, [self::REASON_DUPLICATE, self::REASON_NEAR_DUPLICATE]);
    }

    /** true = tiếp tục nhưng flag human_review (80–90% similarity) */
    public function isSoftDuplicate(): bool
    {
        return $this->reason === self::REASON_SOFT_DUPLICATE;
    }
}
