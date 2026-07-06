<?php

namespace App\Services\AI\AFOS\Ir\Temporal;

/**
 * TemporalAssembler — combines a TrackStore into a TemporalPlan.
 *
 * Separates the assembly concern from Tier3Stage so that Tier3 only receives
 * a fully-assembled TemporalPlan and has no knowledge of how it was built.
 *
 * Called exclusively by TemporalAssemblyStage.
 */
final class TemporalAssembler
{
    public function assemble(float $durationSec, TrackStore $store): TemporalPlan
    {
        return new TemporalPlan($durationSec, ...$store->all());
    }
}
