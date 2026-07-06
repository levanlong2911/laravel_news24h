<?php

namespace App\Services\AI\AFOS\Ir\Temporal;

use App\Services\AI\AFOS\Ir\Temporal\Validation\TimelineValidationResult;

/**
 * TemporalTrack — common contract for all time-coded information layers.
 *
 * Every track (motion, camera, physics, lighting, focus…) lives on the
 * same time axis. This interface lets timeline tools (validator, visualizer,
 * merger, optimizer) operate on any track without knowing its concrete type.
 *
 * Invariant: startTime() <= endTime(); duration() == endTime() - startTime().
 *
 * Round 10: when PhysicsTrack, LightingTrack, AudioTrack exist, Optimizer
 * and TemporalGraph read them all through this interface alone.
 */
interface TemporalTrack
{
    public function startTime(): float;
    public function endTime(): float;
    public function duration(): float;

    /**
     * All events on this track in ascending startSec order.
     * @return TimelineEvent[]
     */
    public function orderedEvents(): array;

    /**
     * Runs all validation passes (duplicate IDs, missing references, cycles,
     * temporal constraints, layer conflicts) and returns a typed result.
     * Requires the EdgeStore from the owning TemporalGraph for passes 2-4.
     */
    public function validate(EdgeStore $edges): TimelineValidationResult;
}
