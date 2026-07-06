<?php

namespace App\Services\AI\AFOS\Passes\Stages;

use App\Services\AI\AFOS\Backend\BackendCapabilityRegistry;
use App\Services\AI\AFOS\Creative\CinematographyProfile;
use App\Services\AI\AFOS\Creative\DirectorProfile;
use App\Services\AI\AFOS\Ir\CameraIR;
use App\Services\AI\AFOS\Ir\CompositionIR;
use App\Services\AI\AFOS\Passes\CompositionToCameraPass;
use App\Services\AI\AFOS\Passes\Pipeline\CompilerPhase;
use App\Services\AI\AFOS\Passes\Pipeline\CompilerStage;
use App\Services\AI\AFOS\Passes\Pipeline\PipelineState;
use App\Services\AI\AFOS\Passes\Pipeline\StageCapability;
use App\Services\AI\AFOS\Passes\Pipeline\StageCost;
use App\Services\AI\AFOS\Passes\Pipeline\StageMetadata;

/**
 * Tier2Stage — CompositionIR → CameraIR.
 *
 * Applies the camera seed pass, backend capability clamping (e.g. Kling
 * does not expose lens data), and any registered camera refinement callables.
 */
final class Tier2Stage implements CompilerStage
{
    /** @param callable[] $refinements fn(CameraIR, DirectorProfile, CinematographyProfile): CameraIR */
    public function __construct(
        private readonly CompositionToCameraPass $pass,
        private array                            $refinements = [],
    ) {}

    public function withPass(CompositionToCameraPass $pass): self
    {
        return new self($pass, $this->refinements);
    }

    public function addRefinement(callable $refinement): self
    {
        return new self($this->pass, [...$this->refinements, $refinement]);
    }

    public function run(PipelineState $state): PipelineState
    {
        $camera     = $this->pass->run($state->composition, $state->director, $state->dp);
        $capability = BackendCapabilityRegistry::get($state->backendId);

        if ($capability && !$capability->supportsLens) {
            $camera = CameraIR::fromArray(
                array_merge($camera->toArray(), ['focalLengthMm' => 50, 'aperture' => 2.8])
            );
        }

        $state->trace?->record('camera_ir', $camera->toArray());
        $state->trace?->recordPass(
            $this->pass->name(),
            $state->composition->toArray(),
            $camera->toArray(),
            $this->pass->parameters()
        );

        foreach ($this->refinements as $refine) {
            $prev   = $camera->toArray();
            $camera = $refine($camera, $state->director, $state->dp);
            $state->trace?->record('camera_ir_refined', $camera->toArray());
            $state->trace?->recordPass('CameraRefinement', $prev, $camera->toArray(), []);
        }

        return $state->withCamera($camera);
    }

    public function name(): string { return 'Tier2Stage'; }

    public function metadata(): StageMetadata
    {
        return new StageMetadata(
            name:           'Tier2Stage',
            reads:          [CompositionIR::class, DirectorProfile::class, CinematographyProfile::class],
            writes:         [CameraIR::class],
            cost:           StageCost::cpu(6.0),
            description:    'CompositionIR → CameraIR: resolves lens, aperture, movement type, and backend capability clamping.',
            deterministic:  true,
            cacheable:      true,
            parallelizable: false,
            category:       'transform',
            capabilities:   [StageCapability::PURE, StageCapability::CACHEABLE, StageCapability::DETERMINISTIC, StageCapability::CPU_INTENSIVE, StageCapability::WRITE_IR],
            phase:          CompilerPhase::LOWER,
        );
    }
}
