<?php

namespace App\Services\AI\AFOS\Passes\Events;

use App\Services\AI\AFOS\Passes\Pipeline\StageProfile;

/** Emitted when a stage throws an exception. The exception is re-thrown after dispatch. */
final class StageFailed implements PipelineEvent
{
    public function __construct(
        public readonly string       $stageName,
        public readonly StageProfile $profile,
        public readonly \Throwable   $exception,
    ) {}
}
