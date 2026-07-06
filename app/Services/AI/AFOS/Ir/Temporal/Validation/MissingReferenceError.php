<?php

namespace App\Services\AI\AFOS\Ir\Temporal\Validation;

use App\Services\AI\AFOS\Ir\Temporal\RelationType;

final class MissingReferenceError extends ValidationError
{
    public function __construct(
        public readonly string       $sourceId,
        public readonly string       $targetId,
        public readonly RelationType $relationType,
    ) {}

    public function message(): string
    {
        return "Event '{$this->sourceId}' has a {$this->relationType->value} relation to missing id '{$this->targetId}'";
    }
}
