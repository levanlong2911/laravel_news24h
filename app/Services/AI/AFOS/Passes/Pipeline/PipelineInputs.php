<?php

namespace App\Services\AI\AFOS\Passes\Pipeline;

use App\Services\AI\AFOS\Creative\CinematographyProfile;
use App\Services\AI\AFOS\Creative\DirectorProfile;
use App\Services\AI\AFOS\Creative\Intent;
use App\Services\AI\AFOS\Ir\ShotGoalIR;
use App\Services\AI\AFOS\Observability\TraceCollector;

/**
 * PipelineInputs — the static, immutable creative brief for one compilation run.
 *
 * These values are fixed at construction and never change as stages execute.
 * Separating them from the progressive IRState makes the compiler lifecycle explicit:
 *   what does the pipeline KNOW from the start (inputs)
 *   vs. what does it DERIVE as it runs (IRState).
 *
 * Stored by PipelineState as $state->inputs; accessed via delegation properties
 * on PipelineState ($state->shot, $state->director, etc.) so stage code is unchanged.
 */
final class PipelineInputs
{
    public function __construct(
        public readonly ShotGoalIR            $shot,
        public readonly DirectorProfile       $director,
        public readonly CinematographyProfile $dp,
        public readonly Intent                $intent,
        public readonly string                $backendId = 'kling',
        public readonly ?TraceCollector       $trace     = null,
    ) {}
}
