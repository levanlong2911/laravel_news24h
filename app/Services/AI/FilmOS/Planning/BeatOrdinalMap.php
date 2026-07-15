<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Planning;

use App\Services\AI\FilmOS\Narrative\Story\StoryBeat;

/**
 * Planning primitive: assigns shot ordinals to the beats present in a piece,
 * following the cinematic order of StoryBeat::cases() and packing them
 * compactly (0, 1, 2…). A piece with only hook + payoff yields hook=0, payoff=1.
 *
 * This is the single source of truth for "which beat becomes which ordinal",
 * used to translate every beat-keyed authored field (emotion arc, scene nodes,
 * production hero_moment / energy_curve / timings, performance) into the
 * ordinals the Knowledge domains store. It matches how ShotPlannedEventFactory
 * numbers shots by iteration order, so callers must build their GoalNode list
 * in orderedBeats() order.
 *
 * A Planning concern (ordinal assignment), not a Benchmark one — reused wherever
 * authored beats are turned into a timeline.
 */
final class BeatOrdinalMap
{
    /** @var array<string, int> beat value => ordinal */
    private array $byValue;

    /** @var StoryBeat[] present beats in cinematic order */
    private array $ordered;

    /** @param StoryBeat[] $present the beats a piece actually contains (order irrelevant) */
    private function __construct(array $present)
    {
        $wanted = [];
        foreach ($present as $beat) {
            $wanted[$beat->value] = true;
        }

        $byValue = [];
        $ordered = [];
        $ordinal = 0;
        foreach (StoryBeat::cases() as $beat) {
            if (isset($wanted[$beat->value])) {
                $byValue[$beat->value] = $ordinal++;
                $ordered[]             = $beat;
            }
        }

        $this->byValue = $byValue;
        $this->ordered = $ordered;
    }

    /** @param StoryBeat[] $present */
    public static function fromBeats(array $present): self
    {
        return new self($present);
    }

    public function has(StoryBeat $beat): bool
    {
        return isset($this->byValue[$beat->value]);
    }

    public function ordinalOf(StoryBeat $beat): int
    {
        return $this->byValue[$beat->value]
            ?? throw new \OutOfBoundsException("Beat '{$beat->value}' is not present in this map.");
    }

    /** @return StoryBeat[] present beats in cinematic order (index = ordinal) */
    public function orderedBeats(): array
    {
        return $this->ordered;
    }

    /** @return array<string, int> beat value => ordinal */
    public function all(): array
    {
        return $this->byValue;
    }
}
