<?php

namespace App\Services\AI\AFOS\Ir\Temporal;

/**
 * MotionTrack — time-coded sequence of MotionBeats for a single shot.
 *
 * Written by MotionBeatStage; read by TemporalAssemblyStage and the serializer.
 *
 * $intent carries the creative intent planned from ShotGoalIR + DirectorProfile.
 * It is NOT computed from the beats — two shots with identical beat timelines
 * can have completely different intents (energyArc, rhythm, emphasis, continuity).
 */
final class MotionTrack extends TimelineTrack
{
    /** Domain track identity — used in NodeRef and TemporalGraph's track index. */
    public const ID = 'motion';

    /**
     * @param MotionBeat[] $events
     */
    public function __construct(
        array                        $events,
        public readonly MotionIntent $intent,
    ) {
        // Enforce ascending startSec order in the stored array
        usort($events, fn(TimelineEvent $a, TimelineEvent $b) => $a->startSec <=> $b->startSec);
        parent::__construct($events);
    }

    /**
     * @return MotionBeat[] Beats in ascending startSec order.
     */
    public function beats(): array
    {
        return $this->events;
    }
}
