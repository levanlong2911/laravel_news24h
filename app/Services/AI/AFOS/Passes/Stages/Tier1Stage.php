<?php

namespace App\Services\AI\AFOS\Passes\Stages;

use App\Services\AI\AFOS\Creative\CinematographyProfile;
use App\Services\AI\AFOS\Creative\DirectorProfile;
use App\Services\AI\AFOS\Ir\CompositionIR;
use App\Services\AI\AFOS\Ir\ShotGoalIR;
use App\Services\AI\AFOS\Passes\Pipeline\CompilerPhase;
use App\Services\AI\AFOS\Passes\Pipeline\CompilerStage;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineState;
use App\Services\AI\AFOS\Passes\Pipeline\StageCapability;
use App\Services\AI\AFOS\Passes\Pipeline\StageCost;
use App\Services\AI\AFOS\Passes\Pipeline\StageMetadata;
use App\Services\AI\AFOS\Passes\ShotGoalToCompositionPass;

/**
 * Tier1Stage — ShotGoalIR → CompositionIR.
 *
 * Runs the seed pass then any registered refinement callables.
 */
final class Tier1Stage implements CompilerStage
{
    /** @param callable[] $refinements fn(CompositionIR, DirectorProfile, CinematographyProfile): CompositionIR */
    public function __construct(
        private readonly ShotGoalToCompositionPass $pass,
        private array                              $refinements = [],
    ) {}

    public function withPass(ShotGoalToCompositionPass $pass): self
    {
        return new self($pass, $this->refinements);
    }

    public function addRefinement(callable $refinement): self
    {
        return new self($this->pass, [...$this->refinements, $refinement]);
    }

    public function run(PipelineState $state): PipelineState
    {
        $composition = $this->pass->run($state->shot, $state->director, $state->dp);
        $state->trace?->record('composition_ir', $composition->toArray());
        $state->trace?->recordPass(
            $this->pass->name(),
            $state->shot->toArray(),
            $composition->toArray(),
            $this->pass->parameters()
        );

        foreach ($this->refinements as $refine) {
            $prev        = $composition->toArray();
            $composition = $refine($composition, $state->director, $state->dp);
            $state->trace?->record('composition_ir_refined', $composition->toArray());
            $state->trace?->recordPass('CompositionRefinement', $prev, $composition->toArray(), []);
        }

        return $state->withComposition($composition);
    }

    public function name(): string { return 'Tier1Stage'; }

    public function metadata(): StageMetadata
    {
        return new StageMetadata(
            name:           'Tier1Stage',
            reads:          [ShotGoalIR::class, DirectorProfile::class, CinematographyProfile::class],
            writes:         [CompositionIR::class],
            cost:           StageCost::cpu(8.0),
            description:    'ShotGoalIR → CompositionIR: resolves composition rule, depth layers, and framing intent.',
            deterministic:  true,
            cacheable:      true,
            parallelizable: false,
            category:       'transform',
            capabilities:   [StageCapability::PURE, StageCapability::CACHEABLE, StageCapability::DETERMINISTIC, StageCapability::CPU_INTENSIVE, StageCapability::WRITE_IR],
            phase:          CompilerPhase::LOWER,
        );
    }
}
