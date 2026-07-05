<?php

namespace App\Services\AI\ScenePlanner\Plans;

/**
 * Typed result from RhythmPlanner::plan().
 *
 * Holds the selected timing pattern and beat timings that replace
 * CinematicBeatPlanner's fixed TIMINGS_5S / TIMINGS_10S defaults.
 * ScenePlanner::applyRhythmTiming() maps these onto the existing beat
 * array in-place — no need to re-run CinematicBeatPlanner.
 */
final class RhythmPlan
{
    /**
     * @param string $pattern     Profile name: cinematic | action_burst | suspense | aerial | viral | product
     * @param array  $beats       [{beat, start, end}] — 4 or 5 beats with computed timings
     * @param float  $durationSec Clip duration the timings were built for
     */
    public function __construct(
        public readonly string $pattern,
        public readonly array  $beats,
        public readonly float  $durationSec,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            pattern:     $data['pattern']                  ?? 'cinematic',
            beats:       $data['beats']                    ?? [],
            durationSec: (float) ($data['duration_sec']   ?? 5.0),
        );
    }

    public function toArray(): array
    {
        return [
            'pattern'      => $this->pattern,
            'beats'        => $this->beats,
            'duration_sec' => $this->durationSec,
        ];
    }

    public function isEmpty(): bool
    {
        return $this->beats === [];
    }

    /** Returns a lookup map of beat_name → {start, end} for fast injection. */
    public function timingMap(): array
    {
        $map = [];
        foreach ($this->beats as $b) {
            $map[$b['beat']] = ['start' => $b['start'], 'end' => $b['end']];
        }
        return $map;
    }
}
