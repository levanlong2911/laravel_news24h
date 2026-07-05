<?php

namespace App\Services\AI\AFOS\Compiler;

use App\Services\AI\AFOS\Compiler\Diagnostics\DiagnosticBag;
use App\Services\AI\AFOS\Creative\CinematographyProfile;
use App\Services\AI\AFOS\Creative\DirectorProfile;
use App\Services\AI\AFOS\Creative\Intent;
use App\Services\AI\AFOS\Ir\ShotGoalIR;
use App\Services\AI\AFOS\Observability\TraceCollector;

/**
 * CompilerContext — unified input/output container for the AFOS compiler pipeline.
 *
 * Every pass receives a CompilerContext instead of individual parameters.
 * The context accumulates diagnostics as passes run; the final snapshot
 * captures the bag as-built.
 *
 * Before context:
 *   compile(ShotGoalIR, DirectorProfile, CinematographyProfile, Intent, ?TraceCollector)
 *
 * After context:
 *   compile(CompilerContext $ctx)
 *
 * When a new input dimension is needed (e.g. DeliverySpec, BudgetConstraint),
 * it is added to CompilerContext — zero pass signatures change.
 */
final class CompilerContext
{
    public readonly DiagnosticBag $diagnostics;

    public function __construct(
        public readonly ShotGoalIR            $shot,
        public readonly DirectorProfile       $director,
        public readonly CinematographyProfile $dp,
        public readonly Intent                $intent,
        public readonly ?TraceCollector       $trace       = null,
        ?DiagnosticBag                        $diagnostics = null,
    ) {
        $this->diagnostics = $diagnostics ?? new DiagnosticBag();
    }

    public static function make(
        ShotGoalIR            $shot,
        DirectorProfile       $director,
        CinematographyProfile $dp,
        Intent                $intent,
        ?TraceCollector       $trace = null,
    ): self {
        return new self($shot, $director, $dp, $intent, $trace);
    }
}
