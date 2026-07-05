<?php

namespace App\Services\AI\AFOS\Passes\Events;

use App\Services\AI\AFOS\Passes\Pipeline\StageMetadata;

/** Emitted immediately before a stage begins executing. */
final class StageStarted implements PipelineEvent
{
    public function __construct(
        public readonly string        $stageName,
        public readonly StageMetadata $metadata,
    ) {}
}
