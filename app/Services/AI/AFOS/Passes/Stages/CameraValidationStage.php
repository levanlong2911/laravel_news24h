<?php

namespace App\Services\AI\AFOS\Passes\Stages;

use App\Services\AI\AFOS\Compiler\Validators\CameraStageValidator;
use App\Services\AI\AFOS\Ir\CameraIR;
use App\Services\AI\AFOS\Passes\Pipeline\CompilerStage;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineState;
use App\Services\AI\AFOS\Passes\Pipeline\StageCapability;
use App\Services\AI\AFOS\Passes\Pipeline\StageCost;
use App\Services\AI\AFOS\Passes\Pipeline\StageMetadata;

/**
 * CameraValidationStage — validates CameraIR after Tier 2 passes run.
 *
 * Non-blocking: diagnostics are appended but compilation continues.
 * Camera IR is backend-adjustable, so errors are downgraded to WARN/HINT
 * by the validators themselves (see CameraIRValidator).
 */
final class CameraValidationStage implements CompilerStage
{
    /** @param CameraStageValidator[] $validators */
    public function __construct(private array $validators = []) {}

    public function withValidator(CameraStageValidator $validator): self
    {
        return new self([...$this->validators, $validator]);
    }

    public function run(PipelineState $state): PipelineState
    {
        foreach ($this->validators as $validator) {
            $validator->validate($state->camera, $state->bag);
        }

        return $state; // diagnostics appended in-place; state shape unchanged
    }

    public function name(): string { return 'CameraValidationStage'; }

    public function metadata(): StageMetadata
    {
        return new StageMetadata(
            name:           'CameraValidationStage',
            reads:          [CameraIR::class],
            writes:         [],
            cost:           StageCost::cpu(0.3),
            description:    'Validates CameraIR after Tier 2; non-blocking (warns, does not abort).',
            deterministic:  true,
            cacheable:      false,
            parallelizable: false,
            category:       'validation',
            capabilities:   [StageCapability::PURE, StageCapability::DETERMINISTIC, StageCapability::READ_ONLY, StageCapability::PARALLEL_SAFE],
        );
    }
}
