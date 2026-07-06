<?php

namespace App\Services\AI\AFOS\Ir\Temporal\Validation;

use App\Services\AI\AFOS\Ir\Temporal\RelationType;

final class TemporalConstraintError extends ValidationError
{
    public function __construct(
        public readonly string       $eventId,
        public readonly string       $targetId,
        public readonly RelationType $relationType,
        public readonly float        $eventStart,
        public readonly float        $targetEnd,
    ) {}

    public function message(): string
    {
        $type = $this->relationType->value;
        return "Temporal violation: '{$this->eventId}' ({$type}) '{$this->targetId}'"
            . " but starts at {$this->eventStart}s before target ends at {$this->targetEnd}s";
    }

    /**
     * Hard constraint violations are errors (scheduling deadlock).
     * Follows/other violations are warnings (timing may be intentional).
     */
    public function severity(): ValidationSeverity
    {
        return $this->relationType === RelationType::Hard
            ? ValidationSeverity::Error
            : ValidationSeverity::Warning;
    }
}
