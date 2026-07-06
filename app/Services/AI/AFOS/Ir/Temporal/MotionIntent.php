<?php

namespace App\Services\AI\AFOS\Ir\Temporal;

/**
 * MotionIntent — creative intent for a MotionTrack.
 *
 * This is NOT computed from the beats — it is planned from Intent, ShotGoalIR,
 * and DirectorProfile. Two shots with identical beat timelines can have
 * completely different intents (calm meditation vs tension before explosion).
 *
 * energyArc:  overall momentum shape — how energy moves through the shot
 *   'build'    starts slow, accelerates toward end
 *   'peak'     maximum energy at midpoint, resolves
 *   'sustain'  constant energy throughout
 *   'resolve'  starts at peak, decelerates to stillness
 *
 * rhythm:  temporal cadence of motion events
 *   'staccato'    short, sharp, separated beats
 *   'legato'      smooth, connected, overlapping beats
 *   'syncopated'  beats fall off the expected pulse
 *   'steady'      regular, predictable intervals
 *
 * emphasis:  body part or spatial focal point that carries the shot's weight
 *   e.g. 'hips' | 'wrist' | 'shoulder' | 'foot' | 'body' | 'head'
 *
 * continuity:  how motion connects across the shot
 *   'flowing'      motion never fully stops; transitions are smooth
 *   'interrupted'  motion stops and restarts; beats have clear gaps
 *   'cyclic'       motion repeats; energy is looped or oscillating
 */
final class MotionIntent
{
    public function __construct(
        public readonly string $energyArc,
        public readonly string $rhythm,
        public readonly string $emphasis,
        public readonly string $continuity,
    ) {}
}
