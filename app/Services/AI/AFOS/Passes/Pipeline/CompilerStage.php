<?php

namespace App\Services\AI\AFOS\Passes\Pipeline;

/**
 * CompilerStage — unit of work in the AFOS compiler pipeline.
 *
 * Each stage receives an immutable PipelineState, does its work, and returns
 * a new PipelineState with one or more artifacts added.
 *
 * AfosPassManager runs:
 *   foreach ($this->stages as $stage) { $state = $stage->run($state); }
 *
 * Stages know nothing about each other — they only read what they need
 * from PipelineState and write one output back.
 */
interface CompilerStage
{
    public function run(PipelineState $state): PipelineState;

    public function name(): string;

    /**
     * Declare what this stage reads from and writes to PipelineState.
     * Used for documentation, pipeline visualization, and future DAG validation.
     */
    public function metadata(): StageMetadata;
}
