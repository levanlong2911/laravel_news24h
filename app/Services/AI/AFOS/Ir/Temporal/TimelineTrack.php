<?php

namespace App\Services\AI\AFOS\Ir\Temporal;

use App\Services\AI\AFOS\Ir\Temporal\Validation\TimelineValidationResult;
use App\Services\AI\AFOS\Ir\Temporal\Validation\TrackValidator;

/**
 * TimelineTrack — abstract base for all temporal tracks.
 *
 * Implements TemporalTrack so TrackStore and TemporalGraph can treat every
 * track generically. Subclasses (MotionTrack, CameraTrack, PhysicsTrack …)
 * add a typed accessor and domain-specific metadata.
 *
 * Invariants enforced by construction:
 *   - $events are sorted ascending by startSec (subclass constructors sort before calling parent).
 *   - Graph integrity can be verified by calling validate().
 *
 * Compiler rules:
 *   - Serializers must call orderedEvents() (or the subclass typed accessor) and iterate.
 *   - No stage may mutate events after the track is built (readonly + immutable).
 */
abstract class TimelineTrack implements TemporalTrack
{
    /** @param TimelineEvent[] $events Already sorted ascending by startSec. */
    public function __construct(protected readonly array $events) {}

    // ── TemporalTrack interface ───────────────────────────────────────────────

    public function startTime(): float
    {
        return $this->events !== [] ? $this->events[0]->startSec : 0.0;
    }

    public function endTime(): float
    {
        if ($this->events === []) {
            return 0.0;
        }
        $last = $this->events[array_key_last($this->events)];
        return $last->endSec;
    }

    public function duration(): float
    {
        return $this->endTime() - $this->startTime();
    }

    // ── Generic accessors ─────────────────────────────────────────────────────

    /** @return TimelineEvent[] Events in ascending startSec order. */
    public function orderedEvents(): array
    {
        return $this->events;
    }

    public function eventCount(): int
    {
        return count($this->events);
    }

    // ── Validation ────────────────────────────────────────────────────────────

    /**
     * Runs all validation passes and returns a typed result.
     * Pass the EdgeStore from the TemporalGraph so passes 2-4 (references, cycles,
     * temporal constraints) can inspect edge structure.
     *
     * Delegates to TrackValidator so each pass is independently testable.
     */
    public function validate(EdgeStore $edges): TimelineValidationResult
    {
        return TrackValidator::validate($this->events, $edges);
    }
}
