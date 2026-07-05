<?php

namespace App\Services\AI\AFOS\Passes\Events;

use App\Services\AI\AFOS\Passes\Pipeline\StageProfile;

/** Emitted after a stage completes successfully. */
final class StageFinished implements PipelineEvent
{
    public function __construct(
        public readonly string       $stageName,
        public readonly StageProfile $profile,
    ) {}
}
