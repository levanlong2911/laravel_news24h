<?php

namespace App\Video\Superyacht\Enums;

enum ArtifactStatus: string
{
    case DRAFT = 'DRAFT';
    case VALIDATING = 'VALIDATING';
    case REVIEW_REQUIRED = 'REVIEW_REQUIRED';
    case APPROVED = 'APPROVED';
    case FROZEN = 'FROZEN';
    case EXECUTED = 'EXECUTED';
    case FAILED = 'FAILED';
    case BLOCKED = 'BLOCKED';
    case STALE = 'STALE';

    /** Allowed next states. A retry after FAILED/STALE is a new revision, never a transition. */
    public function next(): array
    {
        return match ($this) {
            self::DRAFT => [self::VALIDATING, self::STALE],
            self::VALIDATING => [self::REVIEW_REQUIRED, self::APPROVED, self::FAILED, self::BLOCKED, self::STALE],
            self::REVIEW_REQUIRED => [self::APPROVED, self::FAILED, self::STALE],
            self::APPROVED => [self::FROZEN, self::STALE],
            self::FROZEN => [self::EXECUTED, self::FAILED, self::STALE],
            self::EXECUTED => [self::STALE],
            self::BLOCKED => [self::REVIEW_REQUIRED, self::FAILED, self::STALE],
            self::FAILED, self::STALE => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    public function isTerminal(): bool
    {
        return $this->next() === [];
    }

    /** Content may no longer change once an artifact reaches these states. */
    public function isImmutable(): bool
    {
        return in_array($this, [self::APPROVED, self::FROZEN, self::EXECUTED], true);
    }
}
