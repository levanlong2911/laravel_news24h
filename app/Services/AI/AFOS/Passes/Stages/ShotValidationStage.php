<?php

namespace App\Services\AI\AFOS\Passes\Stages;

use App\Services\AI\AFOS\Compiler\Validators\ShotGoalStageValidator;
use App\Services\AI\AFOS\Ir\ShotGoalIR;
use App\Services\AI\AFOS\Passes\Pipeline\CompilerPhase;
use App\Services\AI\AFOS\Passes\Pipeline\CompilerStage;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineState;
use App\Services\AI\AFOS\Passes\Pipeline\StageCapability;
use App\Services\AI\AFOS\Passes\Pipeline\StageCost;
use App\Services\AI\AFOS\Passes\Pipeline\StageMetadata;

/**
 * ShotValidationStage — validates ShotGoalIR before Tier 1 passes run.
 *
 * Blocking: aborts compilation with RuntimeException if any validator
 * appends an ERROR-severity diagnostic.
 */
final class ShotValidationStage implements CompilerStage
{
    /** @param ShotGoalStageValidator[] $validators */
    public function __construct(private array $validators = []) {}

    public function withValidator(ShotGoalStageValidator $validator): self
    {
        return new self([...$this->validators, $validator]);
    }

    public function run(PipelineState $state): PipelineState
    {
        foreach ($this->validators as $validator) {
            $validator->validate($state->shot, $state->bag);
        }

        if ($state->bag->hasErrors()) {
            throw new \RuntimeException(
                "Compilation aborted — IR validation failed:\n" . $state->bag->format()
            );
        }

        return $state;
    }

    public function name(): string { return 'ShotValidationStage'; }

    public function metadata(): StageMetadata
    {
        return new StageMetadata(
            name:           'ShotValidationStage',
            reads:          [ShotGoalIR::class],
            writes:         [],
            cost:           StageCost::cpu(0.5),
            description:    'Validates ShotGoalIR before Tier 1; aborts on ERROR diagnostics.',
            deterministic:  true,
            cacheable:      false,
            parallelizable: false,
            category:       'validation',
            phase:          CompilerPhase::VALIDATE,
            capabilities:   [StageCapability::PURE, StageCapability::DETERMINISTIC, StageCapability::READ_ONLY, StageCapability::PARALLEL_SAFE],
        );
    }
}
